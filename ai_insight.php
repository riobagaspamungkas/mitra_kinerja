<?php
/**
 * AJAX Endpoint untuk AI Insight
 * 
 * GET  → Cek cache, return cached insight
 * POST → Force regenerate, panggil Gemini API
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/data.php';
require_once __DIR__ . '/includes/ai_service.php';
requireLogin();

// Set timezone ke WIB
date_default_timezone_set('Asia/Jakarta');

header('Content-Type: application/json; charset=utf-8');

$pdo = getDB();
$all = getAllMitraSummary($pdo);
$stats = getDashboardStats($all);
$forceRefresh = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');

if (!defined('AI_ENABLED') || !AI_ENABLED) {
    echo json_encode([
        'success' => false,
        'error' => 'AI_DISABLED',
        'message' => 'Fitur AI Insight dinonaktifkan sementara.'
    ]);
    exit;
}

// Cek apakah minimal SATU API key sudah dikonfigurasi
$geminiOk = (GEMINI_API_KEY !== '' && GEMINI_API_KEY !== 'ISI_API_KEY_ANDA_DI_SINI');
$openrouterOk = (OPENROUTER_API_KEY !== '');
$groqOk = (GROQ_API_KEY !== '');

if (!$geminiOk && !$openrouterOk && !$groqOk) {
    echo json_encode([
        'success' => false,
        'error' => 'API_KEY_MISSING',
        'message' => 'Silakan konfigurasi minimal satu API Key (Gemini, OpenRouter, atau Groq) di includes/ai_config.php'
    ]);
    exit;
}

// Coba ambil dari cache dulu
if (!$forceRefresh) {
    $cached = getCachedInsight();
    if ($cached) {
        echo json_encode([
            'success' => true,
            'cached' => true,
            'insight' => $cached['insight'],
            'provider' => $cached['provider'] ?? 'Cache',
            'generated_at' => date('d M Y H:i', $cached['timestamp']),
            'expires_in' => AI_CACHE_TTL - (time() - $cached['timestamp']),
        ]);
        exit;
    }
}

// Generate payload
$payload = generateInsightPayload($pdo, $all, $stats);

// Panggil AI — triple fallback: Gemini → OpenRouter → Groq
$aiResult = generateNewInsight($payload);

if ($aiResult) {
    // $aiResult = ['text' => '...', 'provider' => 'Gemini']
    saveInsightCache($payload, $aiResult['text'], $aiResult['provider']);
    
    echo json_encode([
        'success' => true,
        'cached' => false,
        'insight' => $aiResult['text'],
        'provider' => $aiResult['provider'],
        'generated_at' => date('d M Y H:i'),
        'expires_in' => AI_CACHE_TTL,
    ]);
} else {
    $errorDetail = 'Semua provider gagal (Gemini, OpenRouter, Groq).';
    
    if (!function_exists('curl_init')) {
        $errorDetail = 'cURL extension tidak tersedia di server.';
    }
    
    echo json_encode([
        'success' => false,
        'error' => 'API_ERROR',
        'message' => 'Gagal mengambil analisis dari AI.',
        'detail' => $errorDetail,
        'debug_url' => 'ai_debug.php',
    ]);
}
