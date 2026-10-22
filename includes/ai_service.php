<?php
/**
 * Service AI Analysis — Google Gemini Integration
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/ai_config.php';

/**
 * Generate payload data untuk dikirim ke Gemini API
 */
function generateInsightPayload(PDO $pdo, array $all, array $stats): array {
    // Ambil naskah bermasalah (kritis & berisiko)
    $problemMitra = [];
    foreach ($all as $s) {
        $kategori = $s['kategori'] ?? 'BELUM LENGKAP';
        if (in_array($kategori, ['KRITIS', 'PERLU PERBAIKAN'], true)) {
            $problemMitra[] = [
                'kode' => $s['mitra']['kode'],
                'nama' => $s['mitra']['nama_mitra'],
                'kategori' => $kategori,
                'skor' => $s['nilai_berjalan'],
            ];
        }
    }
    
    // Ambil naskah dengan skor rendah (< 60) dan urutkan dari terendah
    $lowScore = array_filter($all, function($s) {
        return ($s['nilai_berjalan'] ?? 0) > 0 && $s['nilai_berjalan'] < 60;
    });
    usort($lowScore, function($a, $b) {
        return ($a['nilai_berjalan'] ?? 0) <=> ($b['nilai_berjalan'] ?? 0);
    });
    
    // Hitung aspek terlemah (berdasarkan persentase capaian terhadap bobot, izinkan nilai 0)
    $aspekTerlemah = null;
    $minPersen = null;
    if (!empty($stats['aspekRataRata'])) {
        foreach ($stats['aspekRataRata'] as $kode => $rata) {
            $bobot = (float)(BOBOT_INDIKATOR[$kode] ?? 10);
            $persen = $bobot > 0 ? ($rata / $bobot) * 100 : 0.0;
            if ($minPersen === null || $persen < $minPersen) {
                $minPersen = $persen;
                $aspekTerlemah = [
                    'kode' => $kode,
                    'label' => ASPEK_LABELS[$kode] ?? $kode,
                    'rata' => $rata,
                    'bobot' => $bobot,
                    'persen' => round($persen, 1),
                ];
            }
        }
    }
    
    // Hitung tindak lanjut terlambat
    $now = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tindak_lanjut WHERE status != 'Selesai' AND tenggat < ?");
    $stmt->execute([$now]);
    $terlambat = $stmt->fetchColumn();
    
    // Hitung early warning aktif (E2 & E3) dari $all (termasuk kalkulasi dinamis masa berlaku)
    $warningAktif = 0;
    foreach ($all as $s) {
        if (!empty($s['warning_rows']) && is_array($s['warning_rows'])) {
            foreach ($s['warning_rows'] as $w) {
                if (in_array($w['status'] ?? '', ['E2', 'E3'], true)) {
                    $warningAktif++;
                }
            }
        } elseif (isset($s['warning']['status']) && in_array($s['warning']['status'], ['E2', 'E3'], true)) {
            $warningAktif++;
        }
    }
    
    // Breakdown status scorecard — supaya AI tahu KENAPA naskah belum efektif
    $statusBreakdown = [];
    $kelengkapanList = [];
    foreach ($all as $s) {
        $st = $s['status_scorecard'] ?? 'BELUM DIISI';
        $statusBreakdown[$st] = ($statusBreakdown[$st] ?? 0) + 1;
        $kelengkapanList[] = (int)($s['kelengkapan'] ?? 0);
    }
    $rataKelengkapan = count($kelengkapanList) > 0 ? round(array_sum($kelengkapanList) / count($kelengkapanList)) : 0;
    
    return [
        'ringkasan' => [
            'total_naskah' => $stats['total'],
            'pilot' => $stats['pilotCount'],
            'efektif' => $stats['efektif'],
            'perlu_perhatian' => $stats['perluPerhatian'],
            'berisiko' => $stats['berisiko'],
            'rata_rata_skor' => $stats['rataRataNilai'],
            'rata_rata_kelengkapan_persen' => $rataKelengkapan,
            'breakdown_status_scorecard' => $statusBreakdown,
        ],
        'naskah_bermasalah' => array_slice($problemMitra, 0, 5), // Top 5
        'naskah_skor_rendah' => array_map(function($s) {
            return [
                'kode' => $s['mitra']['kode'],
                'nama' => $s['mitra']['nama_mitra'],
                'skor' => $s['nilai_berjalan'],
            ];
        }, array_slice($lowScore, 0, 3)),
        'aspek_terlemah' => $aspekTerlemah,
        'tindak_lanjut_terlambat' => (int)$terlambat,
        'early_warning_aktif' => (int)$warningAktif,
    ];
}

/**
 * Panggil Google Gemini API
 */
function callGeminiAPI(string $prompt): ?string {
    if (empty(GEMINI_API_KEY) || GEMINI_API_KEY === 'ISI_API_KEY_ANDA_DI_SINI') {
        error_log("Gemini: API key not configured, skipping.");
        return null;
    }
    
    if (!function_exists('curl_init')) {
        error_log("Gemini API Error: cURL extension is not installed.");
        return null;
    }
    
    $url = GEMINI_API_URL . GEMINI_MODEL . ':generateContent?key=' . GEMINI_API_KEY;
    
    $payload = json_encode([
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.7,
            'maxOutputTokens' => 1500,
            'topP' => 0.9,
        ]
    ]);
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("Gemini API Error: $error");
        return null;
    }
    
    if ($httpCode !== 200) {
        error_log("Gemini API HTTP Error: $httpCode - $response");
        return null;
    }
    
    $data = json_decode($response, true);
    
    // Log full response for debugging
    error_log("Gemini Response: " . json_encode($data));
    
    // Check for safety blocks or errors
    if (isset($data['promptFeedback']['blockReason'])) {
        error_log("Gemini Blocked: " . $data['promptFeedback']['blockReason']);
        return null;
    }
    
    // Extract text from Gemini response
    if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
        $text = $data['candidates'][0]['content']['parts'][0]['text'];
        
        // Check if output was truncated
        $finishReason = $data['candidates'][0]['finishReason'] ?? 'UNKNOWN';
        if ($finishReason === 'MAX_TOKENS') {
            error_log("Gemini Warning: Output was truncated (MAX_TOKENS). Consider increasing maxOutputTokens.");
            // Still return what we got — it's partial but usable
        }
        
        return $text;
    }
    
    error_log("Gemini: No text in response. Full: " . json_encode($data));
    return null;
}

/**
 * Panggil Groq API (OpenAI-compatible endpoint)
 */
function callGroqAPI(string $prompt): ?string {
    if (GROQ_API_KEY === '') {
        error_log("Groq: API key not configured, skipping.");
        return null;
    }
    
    if (!function_exists('curl_init')) {
        error_log("Groq API Error: cURL extension is not installed.");
        return null;
    }
    
    $url = GROQ_API_URL;
    
    $payload = json_encode([
        'model' => GROQ_MODEL,
        'messages' => [
            [
                'role' => 'user',
                'content' => $prompt,
            ]
        ],
        'temperature' => 0.7,
        'max_tokens' => 1500,
        'top_p' => 0.9,
    ]);
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . GROQ_API_KEY,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("Groq API Error: $error");
        return null;
    }
    
    if ($httpCode !== 200) {
        error_log("Groq API HTTP Error: $httpCode - $response");
        return null;
    }
    
    $data = json_decode($response, true);
    error_log("Groq Response: " . json_encode($data));
    
    // Extract text from Groq response (OpenAI format)
    if (isset($data['choices'][0]['message']['content'])) {
        $text = $data['choices'][0]['message']['content'];
        
        // Check finish reason
        $finishReason = $data['choices'][0]['finish_reason'] ?? 'UNKNOWN';
        if ($finishReason === 'length') {
            error_log("Groq Warning: Output was truncated (length limit).");
        }
        
        return $text;
    }
    
    error_log("Groq: No text in response. Full: " . json_encode($data));
    return null;
}

/**
 * Panggil OpenRouter API (OpenAI-compatible, model GRATIS $0)
 */
function callOpenRouterAPI(string $prompt): ?string {
    if (OPENROUTER_API_KEY === '') {
        error_log("OpenRouter: API key not configured, skipping.");
        return null;
    }
    
    if (!function_exists('curl_init')) {
        error_log("OpenRouter API Error: cURL extension is not installed.");
        return null;
    }
    
    $url = OPENROUTER_API_URL;
    
    $payload = json_encode([
        'model' => OPENROUTER_MODEL,
        'messages' => [
            [
                'role' => 'user',
                'content' => $prompt,
            ]
        ],
        'temperature' => 0.7,
        'max_tokens' => 1500,
        'top_p' => 0.9,
    ]);
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENROUTER_API_KEY,
            'HTTP-Referer: ' . (isset($_SERVER['HTTP_HOST']) ? 'https://' . $_SERVER['HTTP_HOST'] : 'https://mitra-kinerja.local'),
            'X-Title: Mitra Kinerja - Kanwil Kementerian Hukum Kepri',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        error_log("OpenRouter API Error: $error");
        return null;
    }
    
    if ($httpCode !== 200) {
        error_log("OpenRouter API HTTP Error: $httpCode - $response");
        return null;
    }
    
    $data = json_decode($response, true);
    error_log("OpenRouter Response: " . json_encode($data));
    
    // Extract text from OpenRouter response (OpenAI format)
    if (isset($data['choices'][0]['message']['content'])) {
        $text = $data['choices'][0]['message']['content'];
        
        $finishReason = $data['choices'][0]['finish_reason'] ?? 'UNKNOWN';
        if ($finishReason === 'length') {
            error_log("OpenRouter Warning: Output was truncated (length limit).");
        }
        
        return $text;
    }
    
    error_log("OpenRouter: No text in response. Full: " . json_encode($data));
    return null;
}

/**
 * Ambil insight dari cache (jika masih fresh)
 */
function getCachedInsight(): ?array {
    if (!file_exists(AI_CACHE_FILE)) {
        return null;
    }
    
    $cache = json_decode(file_get_contents(AI_CACHE_FILE), true);
    if (!$cache || !isset($cache['timestamp']) || !isset($cache['insight'])) {
        return null;
    }
    
    // Cek apakah cache masih valid
    if ((time() - $cache['timestamp']) > AI_CACHE_TTL) {
        return null; // Cache expired
    }
    
    return $cache;
}

/**
 * Simpan insight ke cache
 */
function saveInsightCache(array $payload, string $insight, string $provider = ''): bool {
    $cacheDir = dirname(AI_CACHE_FILE);
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    
    $data = [
        'timestamp' => time(),
        'generated_at' => date('d M Y H:i'),
        'provider' => $provider,
        'payload' => $payload,
        'insight' => $insight,
    ];
    
    return file_put_contents(AI_CACHE_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}

/**
 * Generate insight baru — Triple fallback: Gemini → OpenRouter → Groq
 * Return: ['text' => '...', 'provider' => 'Gemini'] atau null
 */
function generateNewInsight(array $payload): ?array {
    if (!function_exists('curl_init')) {
        error_log("AI Insight: cURL extension tidak tersedia di server.");
        return null;
    }

    $prompt = sprintf(AI_PROMPT_TEMPLATE, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    // 1️⃣ Gemini (gratis, primary)
    error_log("AI Insight: Mencoba Gemini API...");
    $result = callGeminiAPI($prompt);
    if ($result) {
        error_log("AI Insight: ✅ Gemini berhasil.");
        return ['text' => $result, 'provider' => 'Gemini'];
    }
    
    // 2️⃣ OpenRouter (gratis, fallback #1)
    error_log("AI Insight: ⚠️ Gemini gagal. Fallback ke OpenRouter...");
    $result = callOpenRouterAPI($prompt);
    if ($result) {
        error_log("AI Insight: ✅ OpenRouter berhasil (fallback #1).");
        return ['text' => $result, 'provider' => 'OpenRouter'];
    }
    
    // 3️⃣ Groq (fallback #2, pakai credit)
    error_log("AI Insight: ⚠️ OpenRouter gagal. Fallback ke Groq...");
    $result = callGroqAPI($prompt);
    if ($result) {
        error_log("AI Insight: ✅ Groq berhasil (fallback #2).");
        return ['text' => $result, 'provider' => 'Groq'];
    }
    
    // 4️⃣ Semua gagal
    error_log("AI Insight: ❌ Semua provider gagal (Gemini, OpenRouter, Groq).");
    return null;
}
