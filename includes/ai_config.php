<?php
/**
 * Konfigurasi AI Analysis — Triple Fallback: Gemini → OpenRouter → Groq
 * 
 * Status: Dinonaktifkan sementara (AI_ENABLED = false).
 * Kunci API hardcoded telah disanitasi.
 */

// Optional local overrides
if (file_exists(__DIR__ . '/ai_config.local.php')) {
    require_once __DIR__ . '/ai_config.local.php';
}

if (!defined('AI_ENABLED')) {
    define('AI_ENABLED', filter_var(getenv('AI_ENABLED'), FILTER_VALIDATE_BOOLEAN));
}

// Google Gemini (PRIMARY)
if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');
}
if (!defined('GEMINI_MODEL')) {
    define('GEMINI_MODEL', getenv('GEMINI_MODEL') ?: 'gemini-1.5-flash');
}
if (!defined('GEMINI_API_URL')) {
    define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1/models/');
}

// OpenRouter (FALLBACK #1)
if (!defined('OPENROUTER_API_KEY')) {
    define('OPENROUTER_API_KEY', getenv('OPENROUTER_API_KEY') ?: '');
}
if (!defined('OPENROUTER_MODEL')) {
    define('OPENROUTER_MODEL', getenv('OPENROUTER_MODEL') ?: 'meta-llama/llama-3.3-70b-instruct');
}
if (!defined('OPENROUTER_API_URL')) {
    define('OPENROUTER_API_URL', 'https://openrouter.ai/api/v1/chat/completions');
}

// Groq (FALLBACK #2)
if (!defined('GROQ_API_KEY')) {
    define('GROQ_API_KEY', getenv('GROQ_API_KEY') ?: '');
}
if (!defined('GROQ_MODEL')) {
    define('GROQ_MODEL', getenv('GROQ_MODEL') ?: 'llama-3.3-70b-versatile');
}
if (!defined('GROQ_API_URL')) {
    define('GROQ_API_URL', 'https://api.groq.com/openai/v1/chat/completions');
}

// Cache settings
if (!defined('AI_CACHE_TTL')) {
    define('AI_CACHE_TTL', 3600); // 1 jam dalam detik
}
if (!defined('AI_CACHE_FILE')) {
    define('AI_CACHE_FILE', __DIR__ . '/../cache/ai_insight.json');
}

// Prompt template untuk analisis
if (!defined('AI_PROMPT_TEMPLATE')) {
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
}

