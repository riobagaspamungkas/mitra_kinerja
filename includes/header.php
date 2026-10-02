<?php
/** Dipanggil setelah auth.php di-require dan $pageTitle sudah di-set. */
$user = currentUser();
$currentPage = basename($_SERVER['PHP_SELF'] ?? '', '.php');

/* ── Ikon SVG line-art (putih, 20×20 viewBox) ─────────────── */
$icons = [
    'dashboard' => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3h5v7H3zM12 3h5v4H12zM3 13h5v4H3zM12 10h5v7H12z"/></svg>',
    'gate0' => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="14" height="14" rx="2"/><path d="M7 10h6M10 7v6"/></svg>',
    'portofolio' => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="2" width="14" height="16" rx="2"/><path d="M7 2V0M7 6h6M7 9h4"/></svg>',
    'baseline' => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="2" width="14" height="16" rx="2"/><path d="M6 6h8M6 9h8M6 12h5"/></svg>',
    'mitra_manage' => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="2" width="14" height="16" rx="2"/><path d="M7 6h6M7 10h4"/><circle cx="10" cy="14" r="1.5" fill="currentColor"/><path d="M10 4v-1"/></svg>',
    'import' => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14v2a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2v-2M7 9l3 3 3-3M10 12V2"/></svg>',
    'scorecard' => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="10" width="3" height="6" rx=".5"/><rect x="6.5" y="7" width="3" height="9" rx=".5"/><rect x="11" y="4" width="3" height="12" rx=".5"/><path d="M15.5 2v16"/><path d="M15 2l2.5 2.5-2.5 2.5"/></svg>',
    'early_warning' => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10 2a5 5 0 0 1 5 5c0 3.5 1.5 4.5 2 5H3c.5-.5 2-1.5 2-5a5 5 0 0 1 5-5z"/><path d="M8 16h4"/></svg>',
    'tindak_lanjut' => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="2" width="14" height="16" rx="2"/><path d="M7 10l2 2 4-4"/></svg>',
    'laporan' => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="12" width="3.5" height="5" rx=".5"/><rect x="8.25" y="8" width="3.5" height="9" rx=".5"/><rect x="14.5" y="4" width="3.5" height="13" rx=".5"/></svg>',
    'mitra_validasi' => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M7 10l2 2 4-4"/><circle cx="10" cy="10" r="8"/></svg>',
    'users_list' => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="7" cy="6" r="3"/><path d="M1 16c0-3 2.5-5 6-5s6 2 6 5"/><circle cx="14" cy="6" r="2.5"/><path d="M15 11c2 0 4 1.5 4 4"/></svg>',
];

$role = $user['role'] ?? 'pemeriksa';

$allNavItems = [
    ['page' => 'dashboard',      'label' => 'Dashboard',         'roles' => ['admin','pemeriksa','validator','pimpinan','pengampu','pic']],
    ['page' => 'gate0',          'label' => 'Gate 0 (Pra-PKS)',  'roles' => ['admin','pemeriksa','pimpinan','pengampu']],
    ['page' => 'portofolio',     'label' => 'Portofolio',        'roles' => ['admin','pemeriksa','validator','pimpinan','pengampu','pic']],
    ['page' => 'mitra_manage',   'label' => 'Manajemen Naskah',  'roles' => ['admin','pemeriksa','pengampu']],
    ['page' => 'baseline',       'label' => 'Baseline',          'roles' => ['admin','pemeriksa','validator','pimpinan','pengampu','pic']],
    ['page' => 'scorecard',      'label' => 'Scorecard',         'roles' => ['admin','pemeriksa','validator','pimpinan','pengampu','pic']],
    ['page' => 'mitra_validasi', 'label' => 'Penilaian',         'roles' => ['admin','pemeriksa','validator']],
    ['page' => 'early_warning',  'label' => 'Early Warning',     'roles' => ['admin','pemeriksa','validator','pimpinan','pengampu','pic']],
    ['page' => 'tindak_lanjut',  'label' => 'Tindak Lanjut',     'roles' => ['admin','pemeriksa','validator','pimpinan','pengampu','pic']],
    ['page' => 'laporan',        'label' => 'Laporan',           'roles' => ['admin','pemeriksa','validator','pimpinan','pengampu','pic']],
];

$navItems = array_filter($allNavItems, fn($n) => in_array($role, $n['roles'], true));

$initials = '';
if ($user) {
    $parts = preg_split('/\s+/', trim($user['nama']));
    $initials = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) $initials .= strtoupper(substr(end($parts), 0, 1));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($pageTitle ?? 'Mitra Kinerja') ?> — Mitra Kinerja</title>
<link rel="stylesheet" href="public/css/style.css?v=<?= filemtime(__DIR__ . '/../public/css/style.css') ?>">
</head>
<body>
<?php if ($user): ?>
<div class="app-layout">
<div class="app-layout-main">
    <aside class="sidebar">
        <nav class="sidebar-nav">
            <?php foreach ($navItems as $nav): ?>
            <a href="<?= $nav['page'] ?>.php" class="<?= $currentPage === $nav['page'] ? 'active' : '' ?>">
                <span class="nav-icon"><?= $icons[$nav['page']] ?? '' ?></span><span><?= $nav['label'] ?></span>
            </a>
            <?php endforeach; ?>
            <?php if ($user['role'] === 'admin'): ?>
            <a href="users_list.php" class="<?= $currentPage === 'users_list' ? 'active' : '' ?>">
                <span class="nav-icon"><?= $icons['users_list'] ?? '' ?></span><span>Pengguna</span>
            </a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-motto">Kanwil Kementerian Hukum<br>Kepulauan Riau</div>
    </aside>
    <div class="app-content">
        <header class="app-hero">
            <div class="hero-left">
                <div class="hero-logo-block">
                    <div class="hero-logo-badge">
                        <img src="public/img/logo-hukum.png" alt="Logo Hukum">
                    </div>
                    <div class="hero-instansi">
                        <strong>KEMENTERIAN HUKUM</strong>
                        <span>KANTOR WILAYAH KEPULAUAN RIAU</span>
                    </div>
                </div>
                <div class="hero-titles">
                    <div class="hero-title">MITRA KINERJA</div>
                    <div class="hero-subtitle">Monitoring &amp; Evaluasi Kerja Sama</div>
                </div>
            </div>
            <div class="hero-right">
                <div class="header-user">
                    <div class="user-avatar"><?= h($initials) ?></div>
                    <div class="user-info">
                        <div class="user-name"><?= h($user['nama']) ?></div>
                        <div class="user-role"><?= h(ucfirst($user['role'])) ?></div>
                    </div>
                    <a href="logout.php" class="btn-logout">▾</a>
                </div>
            </div>
        </header>
        <div class="page-body">
<?php else: ?>
<div class="page-body">
<?php endif; ?>
