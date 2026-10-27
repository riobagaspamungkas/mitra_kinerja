<?php
require_once __DIR__ . '/includes/auth.php';

if (currentUser()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $lockoutTime = 60; // 60 detik lockout
    $maxAttempts = 5;  // 5 kali percobaan

    // Dapatkan alamat IP klien (hanya percaya HTTP_X_FORWARDED_FOR jika REMOTE_ADDR ada di daftar trusted proxy)
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $trustedProxies = defined('TRUSTED_PROXIES') && is_array(TRUSTED_PROXIES)
        ? TRUSTED_PROXIES
        : (defined('TRUSTED_PROXIES') && is_string(TRUSTED_PROXIES) ? array_map('trim', explode(',', TRUSTED_PROXIES)) : []);

    if (in_array($clientIp, $trustedProxies, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $fwd = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($fwd, FILTER_VALIDATE_IP)) {
            $clientIp = $fwd;
        }
    }

    $rawUser = isset($_POST['username']) && is_string($_POST['username']) ? $_POST['username'] : '';
    $rawPass = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
    $username = trim($rawUser);
    $password = $rawPass;

    // Cek kegagalan login terkini di database (audit_log) dalam 5 menit terakhir
    $dbAttempts = 0;
    $lastFailedTime = 0;
    try {
        $pdo = getDB();
        $lastSuccessTime = null;
        if ($username !== '') {
            $stmtSuccess = $pdo->prepare("
                SELECT MAX(al.created_at) 
                FROM audit_log al 
                JOIN users u ON al.user_id = u.id 
                WHERE al.aksi = 'LOGIN' AND u.username = ?
            ");
            $stmtSuccess->execute([$username]);
            $lastSuccessTime = $stmtSuccess->fetchColumn() ?: null;
        }

        $escapedIp = addcslashes($clientIp, '%_\\');

        if ($username !== '') {
            $escapedUsername = addcslashes($username, '%_\\');
            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS failed_count, MAX(UNIX_TIMESTAMP(created_at)) AS last_failed
                FROM audit_log
                WHERE aksi = 'LOGIN_FAILED'
                  AND created_at >= NOW() - INTERVAL 5 MINUTE
                  AND (? IS NULL OR created_at > ?)
                  AND (
                      detail LIKE ?
                      OR detail LIKE ?
                  )
            ");
            $stmt->execute([
                $lastSuccessTime,
                $lastSuccessTime,
                '%Username: ' . $escapedUsername . '%',
                '%IP: ' . $escapedIp . '%'
            ]);
        } else {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS failed_count, MAX(UNIX_TIMESTAMP(created_at)) AS last_failed
                FROM audit_log
                WHERE aksi = 'LOGIN_FAILED'
                  AND created_at >= NOW() - INTERVAL 5 MINUTE
                  AND detail LIKE ?
            ");
            $stmt->execute(['%IP: ' . $escapedIp . '%']);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $dbAttempts = (int)($row['failed_count'] ?? 0);
            $lastFailedTime = (int)($row['last_failed'] ?? 0);
        }
    } catch (Throwable $e) {}

    // Sinergi session + DB: ambil nilai attempt dan lockout tertinggi
    $sessionLockUntil = (int)($_SESSION['login_lockout'] ?? 0);
    $dbLockUntil = ($dbAttempts >= $maxAttempts && $lastFailedTime > 0) ? ($lastFailedTime + $lockoutTime) : 0;
    $lockUntil = max($sessionLockUntil, $dbLockUntil);

    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_POST['ajax']) && $_POST['ajax'] === '1');

    if ($lockUntil > time()) {
        $remaining = $lockUntil - time();
        $error = "Terlalu banyak percobaan login gagal. Silakan tunggu {$remaining} detik.";
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => $error, 'csrf_token' => csrfToken()]);
            exit;
        }
    } else {
        $token = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null;
        if ($token === null || !verifyCsrfToken($token)) {
            $error = 'Token keamanan tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.';
        } elseif ($username === '' || $password === '') {
            $error = 'Username dan password wajib diisi.';
        } elseif (attemptLogin($username, $password)) {
            unset($_SESSION['login_attempts'], $_SESSION['login_lockout']);
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'redirect' => 'dashboard.php']);
                exit;
            }
            header('Location: dashboard.php');
            exit;
        } else {
            // Catat kegagalan ke audit_log (menyimpan Username dan IP, sanitasi delimiter untuk mencegah log injection)
            $safeLogUser = str_replace(["\x0d", "\x0a"], '', str_replace([' | ', '|'], [' ', '_'], $username));
            logAudit(null, null, 'LOGIN_FAILED', 'Username: ' . $safeLogUser . ' | IP: ' . $clientIp);
            $attempts = max((int)($_SESSION['login_attempts'] ?? 0), $dbAttempts) + 1;
            if ($attempts >= $maxAttempts) {
                $_SESSION['login_lockout'] = time() + $lockoutTime;
                $_SESSION['login_attempts'] = 0;
                $error = "Terlalu banyak percobaan login gagal. Akun dikunci sementara selama {$lockoutTime} detik.";
            } else {
                $_SESSION['login_attempts'] = $attempts;
                $sisa = $maxAttempts - $attempts;
                $error = "Username atau password salah. (Sisa percobaan: {$sisa})";
            }
        }
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => $error, 'csrf_token' => csrfToken()]);
            exit;
        }
    }
}
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Masuk — Mitra Kinerja</title>
<link rel="stylesheet" href="public/css/style.css?v=<?= filemtime(__DIR__ . '/public/css/style.css') ?>">
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <div class="login-brand">
            <div class="logo-circle" style="overflow:hidden;padding:0;">
                <img src="public/img/logo-hukum.png?v=<?= filemtime(__DIR__ . '/public/img/logo-hukum.png') ?>" alt="Logo Pengayoman" style="width:100%;height:100%;object-fit:cover;display:block;">
            </div>
            <h1>MITRA KINERJA</h1>
            <p>Monitoring &amp; Evaluasi Kerja Sama<br>Kanwil Kementerian Hukum Kepulauan Riau</p>
        </div>
        <?php if (!empty($_GET['switch'])): ?>
            <div style="background:rgba(239,246,255,0.85);backdrop-filter:blur(6px);border:1px solid #bfdbfe;color:#1e40af;padding:10px 14px;border-radius:8px;font-size:12.5px;margin-bottom:14px;display:flex;align-items:center;gap:8px;">
                <span style="font-size:16px;">🔄</span>
                <span>Sesi sebelumnya telah diakhiri. Silakan masuk dengan akun yang ingin Anda gunakan.</span>
            </div>
        <?php endif; ?>
        <div id="loginErrorBox" class="error-box" style="<?= $error ? '' : 'display:none;' ?>"><?= h($error) ?></div>
        <form id="loginForm" method="post">
            <?= csrfField() ?>
            <label for="username">Username</label>
            <input type="text" id="username" name="username" autofocus required>
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
            <button type="submit" id="btnLoginSubmit">Masuk</button>
        </form>
        
        <?php if (defined('APP_ENV') && APP_ENV === 'development'): ?>
        <div class="demo-box">
            <div style="font-weight:600;margin-bottom:8px;color:#1e293b;display:flex;align-items:center;gap:6px;">
                <span>🔑</span> Akun Demo Pengujian (Klik untuk isi cepat):
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
                <button type="button" class="btn-demo" onclick="fillLogin('admin','admin123')">Admin</button>
                <button type="button" class="btn-demo" onclick="fillLogin('pemeriksa','pemeriksa123')">Pemeriksa</button>
                <button type="button" class="btn-demo" onclick="fillLogin('pengampu','pengampu123')">Pengampu</button>
                <button type="button" class="btn-demo" onclick="fillLogin('pic','pic123')">PIC Kerja Sama</button>
                <button type="button" class="btn-demo" onclick="fillLogin('validator','validator123')">Validator</button>
                <button type="button" class="btn-demo" onclick="fillLogin('pimpinan','pimpinan123')">Pimpinan</button>
            </div>
        </div>
        <?php endif; ?>

        <div class="login-hint">Hubungi admin jika lupa kata sandi.</div>
    </div>
</div>

<!-- Glassmorphism 3-Second Loading Overlay -->
<div id="loginLoadingOverlay" class="login-loading-overlay" aria-hidden="true">
    <div class="login-loading-glass">
        <div class="loading-logo-box">
            <div class="loading-logo-circle" style="overflow:hidden;padding:0;">
                <img src="public/img/logo-hukum.png?v=<?= filemtime(__DIR__ . '/public/img/logo-hukum.png') ?>" alt="Logo Pengayoman" id="loadingLogoImg" style="width:100%;height:100%;object-fit:cover;display:block;">
                <span id="loadingLogoText" style="display:none;">MK</span>
            </div>
            <div class="loading-pulse-ring"></div>
            <div class="loading-pulse-ring delay-1"></div>
        </div>

        <h3 class="loading-title">MITRA KINERJA</h3>
        <p id="loadingStatusText" class="loading-status-text">Memverifikasi kredensial akun...</p>

        <!-- Progress bar matching glass theme -->
        <div class="loading-bar-wrap">
            <div id="loadingBarFill" class="loading-bar-fill"></div>
        </div>

        <div class="loading-footer-info">
            <span id="loadingPercentText" class="loading-percent">0%</span>
            <span class="loading-instansi">Kanwil Kementerian Hukum Kepri</span>
        </div>
    </div>
</div>

<script>
function fillLogin(u, p) {
    document.getElementById('username').value = u;
    document.getElementById('password').value = p;
}

(function() {
    const loginForm = document.getElementById('loginForm');
    const btnSubmit = document.getElementById('btnLoginSubmit');
    const loadingOverlay = document.getElementById('loginLoadingOverlay');
    const loadingBarFill = document.getElementById('loadingBarFill');
    const loadingPercentText = document.getElementById('loadingPercentText');
    const loadingStatusText = document.getElementById('loadingStatusText');
    const loginErrorBox = document.getElementById('loginErrorBox');
    const loadingLogoImg = document.getElementById('loadingLogoImg');
    const loadingLogoText = document.getElementById('loadingLogoText');

    if (!loginForm) return;

    let isSubmitting = false;

    loginForm.addEventListener('submit', function(e) {
        e.preventDefault();
        if (isSubmitting) return;

        const username = document.getElementById('username').value.trim();
        const password = document.getElementById('password').value;

        if (!username || !password) {
            loginErrorBox.textContent = 'Username dan password wajib diisi.';
            loginErrorBox.style.display = 'block';
            return;
        }

        isSubmitting = true;
        btnSubmit.disabled = true;
        loginErrorBox.style.display = 'none';

        // Tampilkan Loading Glass Overlay
        loadingOverlay.classList.add('active');
        loadingOverlay.setAttribute('aria-hidden', 'false');
        loadingBarFill.style.width = '0%';
        loadingPercentText.textContent = '0%';
        loadingStatusText.textContent = 'Memverifikasi data akun...';
        loadingStatusText.style.color = '#1e3a8a';
        if (loadingLogoImg) loadingLogoImg.style.display = 'block';
        if (loadingLogoText) loadingLogoText.style.display = 'none';

        const startTime = performance.now();
        const totalDuration = 3000; // Delay 3 detik tepat
        let animationFrameId = null;

        // Kirim request autentikasi ke server
        const formData = new FormData(loginForm);
        formData.append('ajax', '1');

        const authPromise = fetch('login.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(res => res.json())
        .catch(err => {
            return { success: false, error: 'Terjadi gangguan jaringan atau server. Silakan coba lagi.' };
        });

        // Loop animasi progress 3 detik
        function updateProgress(now) {
            const elapsed = now - startTime;
            const progress = Math.min(1, elapsed / totalDuration);
            const percent = Math.floor(progress * 100);

            loadingBarFill.style.width = percent + '%';
            loadingPercentText.textContent = percent + '%';

            // Pesan status dinamis selaras tema sistem
            if (progress < 0.35) {
                loadingStatusText.textContent = 'Memverifikasi kredensial akun...';
            } else if (progress < 0.70) {
                loadingStatusText.textContent = 'Menghubungkan ke sistem Kanwil...';
            } else if (progress < 0.95) {
                loadingStatusText.textContent = 'Menyiapkan Dashboard Wilayah...';
            } else {
                loadingStatusText.textContent = 'Autentikasi terverifikasi!';
            }

            if (progress < 1) {
                animationFrameId = requestAnimationFrame(updateProgress);
            }
        }

        animationFrameId = requestAnimationFrame(updateProgress);

        // Tunggu hingga durasi 3 detik selesai DAN respon autentikasi siap
        const timerPromise = new Promise(resolve => setTimeout(resolve, totalDuration));

        Promise.all([authPromise, timerPromise]).then(([result]) => {
            if (animationFrameId) cancelAnimationFrame(animationFrameId);

            if (result && result.success) {
                // Sukses: Tampilkan 100% dan efek sukses lalu redirect
                loadingBarFill.style.width = '100%';
                loadingPercentText.textContent = '100%';
                loadingStatusText.textContent = 'Selamat datang! Mengalihkan...';
                loadingStatusText.style.color = '#15803d';
                if (loadingLogoImg) loadingLogoImg.style.display = 'none';
                if (loadingLogoText) {
                    loadingLogoText.style.display = 'block';
                    loadingLogoText.innerHTML = '&#10003;'; // Checkmark
                }

                setTimeout(function() {
                    window.location.href = result.redirect || 'dashboard.php';
                }, 250);
            } else {
                // Gagal: Tampilkan pesan gagal, tutup overlay, dan tampilkan pesan di login card
                loadingStatusText.textContent = 'Autentikasi Gagal';
                loadingStatusText.style.color = '#dc2626';

                if (result && result.csrf_token) {
                    const csrfInput = loginForm.querySelector('input[name="csrf_token"]');
                    if (csrfInput) csrfInput.value = result.csrf_token;
                }

                setTimeout(function() {
                    loadingOverlay.classList.remove('active');
                    loadingOverlay.setAttribute('aria-hidden', 'true');
                    isSubmitting = false;
                    btnSubmit.disabled = false;
                    if (loadingLogoImg) loadingLogoImg.style.display = 'block';
                    if (loadingLogoText) loadingLogoText.style.display = 'none';

                    const errMsg = (result && result.error) ? result.error : 'Username atau password salah.';
                    loginErrorBox.textContent = errMsg;
                    loginErrorBox.style.display = 'block';
                    document.getElementById('password').value = '';
                    document.getElementById('password').focus();
                }, 600);
            }
        });
    });
})();
</script>
</body>
</html>
