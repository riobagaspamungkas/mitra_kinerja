<?php
/**
 * Diagnostic Tool - AI Insight Debug
 * HAPUS SETELAH SELESAI DEBUG!
 */
require_once __DIR__ . '/includes/auth.php';
requireLogin();
$user = currentUser();
if ($user['role'] !== 'admin') {
    http_response_code(403);
    die('Akses ditolak: role Anda (' . htmlspecialchars($user['role']) . ') tidak memiliki izin untuk mengakses halaman ini.');
}
require_once __DIR__ . '/includes/ai_config.php';
?>
<!DOCTYPE html><html><head><title>AI Debug</title>
<style>body{font-family:monospace;padding:20px;background:#f5f5f5}.c{padding:12px;margin:8px 0;background:#fff;border-radius:8px;border-left:4px solid #ccc}.p{border-color:#16a34a}.f{border-color:#dc2626}.w{border-color:#ca8a04}pre{background:#1e293b;color:#e2e8f0;padding:16px;border-radius:8px;overflow-x:auto;font-size:12px}</style>
</head><body>
<h1>🔍 AI Insight Debug</h1>

<h2>1. Environment</h2>
<div class="c <?= function_exists('curl_init')?'p':'f' ?>">
    cURL: <?= function_exists('curl_init')?'✅ OK':'❌ MISSING' ?>
</div>
<div class="c p">PHP: <?= phpversion() ?></div>

<h2>2. API Key</h2>
<?php $ph = GEMINI_API_KEY === 'ISI_API_KEY_ANDA_DI_SINI'; ?>
<div class="c <?= $ph?'f':'p' ?>">
    Key: <?= $ph?'❌ BELUM DIISI':'✅ '.substr(GEMINI_API_KEY,0,8).'...' ?>
</div>

<h2>3. Cache</h2>
<?php $cd = dirname(AI_CACHE_FILE); ?>
<div class="c <?= is_dir($cd)?'p':'f' ?>">
    Folder cache/: <?= is_dir($cd)?'✅ Ada':'❌ Tidak ada' ?>
</div>
<div class="c <?= is_writable($cd)?'p':'f' ?>">
    Writable: <?= is_writable($cd)?'✅ Ya':'❌ Tidak' ?>
</div>

<?php if (!$ph && function_exists('curl_init')): ?>
<h2>4. Test API</h2>
<button onclick="doTest()" style="padding:12px 24px;background:#2563eb;color:#fff;border:none;border-radius:8px;cursor:pointer">🚀 Test Gemini API</button>
<div id="res" style="margin-top:16px"></div>
<script>
function doTest(){
    var el=document.getElementById('res');
    el.innerHTML='<div class="c w">⏳ Menghubungi Gemini API...</div>';
    fetch('ai_debug_raw.php').then(function(r){return r.json()}).then(function(d){
        if(d.success){
            el.innerHTML='<div class="c p"><strong>✅ BERHASIL!</strong><pre>'+d.ai_response.replace(/</g,'&lt;')+'</pre></div>';
        } else {
            el.innerHTML='<div class="c f"><strong>❌ GAGAL</strong><br>HTTP: '+d.http_code+'<br>cURL Error: '+(d.curl_error||'none')+'<pre>'+JSON.stringify(d,null,2).replace(/</g,'&lt;')+'</pre></div>';
        }
    }).catch(function(e){el.innerHTML='<div class="c f">Error: '+e.message+'</div>'});
}
</script>
<?php endif; ?>

<hr><p><a href="dashboard.php">← Dashboard</a> | <strong style="color:red">⚠️ HAPUS file ini setelah debug!</strong></p>
</body></html>
