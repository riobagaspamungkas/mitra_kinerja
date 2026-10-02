<?php
/**
 * Konfigurasi AI Analysis — Triple Fallback: Gemini → OpenRouter → Groq
 * 
 * Status: Dinonaktifkan sementara (AI_ENABLED = false).
 * Kunci API hardcoded telah disanitasi.
 */

define('AI_ENABLED', false);

/* ── 1. Google Gemini (PRIMARY) ─────────────────────────── */
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');
define('GEMINI_MODEL', getenv('GEMINI_MODEL') ?: 'gemini-1.5-flash');
define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1/models/');

/* ── 2. OpenRouter (FALLBACK #1) ───────────────────────── */
define('OPENROUTER_API_KEY', getenv('OPENROUTER_API_KEY') ?: '');
define('OPENROUTER_MODEL', getenv('OPENROUTER_MODEL') ?: 'google/gemini-2.0-flash-exp:free');
define('OPENROUTER_API_URL', 'https://openrouter.ai/api/v1/chat/completions');

/* ── 3. Groq (FALLBACK #2) ─────────────────────────────── */
define('GROQ_API_KEY', getenv('GROQ_API_KEY') ?: '');
define('GROQ_MODEL', getenv('GROQ_MODEL') ?: 'llama-3.3-70b-versatile');
define('GROQ_API_URL', 'https://api.groq.com/openai/v1/chat/completions');

// Cache settings
define('AI_CACHE_TTL', 3600); // 1 jam dalam detik
define('AI_CACHE_FILE', __DIR__ . '/../cache/ai_insight.json');

// Prompt template untuk analisis
define('AI_PROMPT_TEMPLATE', <<<'PROMPT'
Anda adalah asisten analis untuk sistem monitoring kerja sama Kanwil Kementerian Hukum Kepri.

Berdasarkan data portofolio berikut, buatlah ringkasan insight dalam Bahasa Indonesia yang profesional dan singkat (maksimal 3 paragraf pendek).

Fokus pada:
1. Status umum portofolio (berapa sehat, bermasalah, kritis)
2. Temuan utama yang perlu perhatian (aspek terlemah, tenggat terlewat)
3. Satu rekomendasi konkret untuk perbaikan

Hindari: basa-basi, pengulangan, bullet points berlebihan.
Gaya: seperti briefing untuk pimpinan, padat dan actionable.

Data portofolio:
%s

Format output: Langsung isi insight, tanpa pembukaan "Berikut adalah..." atau sejenisnya.
PROMPT
);

