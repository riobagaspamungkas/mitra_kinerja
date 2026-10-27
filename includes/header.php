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
$headerNotifs = [];
$totalNotifCount = 0;
$overdueNotifCount = 0;
$dueSoonNotifCount = 0;

if ($user) {
    $parts = preg_split('/\s+/', trim($user['nama']));
    $initials = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) $initials .= strtoupper(substr(end($parts), 0, 1));

    $userPhoto = $user['foto'] ?? null;
    $userPhotoUrl = ($userPhoto && file_exists(__DIR__ . '/../' . $userPhoto)) ? ($userPhoto . '?v=' . filemtime(__DIR__ . '/../' . $userPhoto)) : null;

    try {
        require_once __DIR__ . '/data.php';
        $pdoNotif = function_exists('getDB') ? getDB() : null;
        if ($pdoNotif) {
            $allSummaryForHeader = (isset($all) && is_array($all)) ? $all : getAllMitraSummary($pdoNotif);
            foreach ($allSummaryForHeader as $s) {
                if (!empty($s['monev']['has_overdue']) && !empty($s['monev']['overdue_milestones'])) {
                    foreach ($s['monev']['overdue_milestones'] as $oms) {
                        $headerNotifs[] = [
                            'type'        => 'overdue',
                            'mitra_id'    => $s['mitra']['id'] ?? 0,
                            'kode'        => $s['mitra']['kode'] ?? '',
                            'nama_mitra'  => $s['mitra']['nama_mitra'] ?? '',
                            'siklus'      => $oms['nama'] ?? 'Siklus Evaluasi',
                            'target_tgl'  => $oms['target_tgl'] ?? '',
                            'sisa_hari'   => $oms['sisa_hari'] ?? 0,
                            'link'        => 'mitra_edit.php?id=' . ($s['mitra']['id'] ?? 0),
                        ];
                        $overdueNotifCount++;
                    }
                }
                if (!empty($s['monev']['warning_1_bulan'])) {
                    $msLabel = 'SC-1';
                    if (!empty($s['monev']['milestones'])) {
                        foreach ($s['monev']['milestones'] as $ms) {
                            $isCompleted = in_array(strtolower(trim($ms['status_siklus'] ?? '')), ['selesai', 'selesai evaluasi'], true);
                            if (!$isCompleted && (($ms['target_tgl'] ?? '') === ($s['monev']['target_evaluasi_terdekat'] ?? '') || !empty($ms['is_due_soon']))) {
                                $msLabel = $ms['nama'] ?? 'SC-1';
                                break;
                            }
                        }
                    }
                    $diff = $s['monev']['hari_menuju_evaluasi'] ?? 0;
                    $headerNotifs[] = [
                        'type'        => 'due_soon',
                        'mitra_id'    => $s['mitra']['id'] ?? 0,
                        'kode'        => $s['mitra']['kode'] ?? '',
                        'nama_mitra'  => $s['mitra']['nama_mitra'] ?? '',
                        'siklus'      => $msLabel,
                        'target_tgl'  => $s['monev']['target_evaluasi_terdekat'] ?? '',
                        'sisa_hari'   => $diff,
                        'link'        => 'mitra_edit.php?id=' . ($s['mitra']['id'] ?? 0),
                    ];
                    $dueSoonNotifCount++;
                }
            }
            $totalNotifCount = count($headerNotifs);
        }
    } catch (Throwable $e) {}
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
    <div class="mobile-nav-backdrop" id="mobileNavBackdrop" onclick="toggleMobileNav()"></div>
    <aside class="sidebar">
        <div class="sidebar-header-mobile">
            <span style="font-weight:700;font-size:14px;color:#fff;">Menu Navigasi</span>
            <button type="button" class="sidebar-close-mobile" onclick="toggleMobileNav()" aria-label="Tutup Menu">&times;</button>
        </div>
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
                <button type="button" class="mobile-nav-toggle" id="mobileNavToggle" aria-label="Buka Menu Navigasi" onclick="toggleMobileNav()">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
                <div class="hero-logo-block">
                    <div class="hero-logo-badge">
                        <img src="public/img/logo-hukum.png?v=<?= filemtime(__DIR__ . '/../public/img/logo-hukum.png') ?>" alt="Logo Kementerian Hukum">
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
                <!-- Notification Bell Button -->
                <div class="header-notif-wrap" id="headerNotifWrap">
                    <button type="button" class="header-notif-btn" id="headerNotifBtn" onclick="toggleNotifDropdown(event)" aria-label="Notifikasi" title="Notifikasi (<?= $totalNotifCount ?> pemberitahuan)">
                        <svg class="notif-bell-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                        </svg>
                        <?php if ($totalNotifCount > 0): ?>
                        <span class="notif-badge <?= $overdueNotifCount > 0 ? 'has-urgent' : '' ?>">
                            <?= $totalNotifCount > 99 ? '99+' : $totalNotifCount ?>
                        </span>
                        <?php endif; ?>
                    </button>

                    <!-- Dropdown Content -->
                    <div class="notif-dropdown" id="headerNotifDropdown" onclick="event.stopPropagation()">
                        <div class="notif-dropdown-header">
                            <div class="notif-header-title">
                                <strong>Pemberitahuan</strong>
                                <span class="notif-total-badge"><?= $totalNotifCount ?> Baru</span>
                            </div>
                            <div class="notif-tabs">
                                <button type="button" class="notif-tab-btn active" onclick="filterNotifTab('all', this)">Semua</button>
                                <?php if ($overdueNotifCount > 0): ?>
                                <button type="button" class="notif-tab-btn" onclick="filterNotifTab('overdue', this)">🚨 Terlewat (<?= $overdueNotifCount ?>)</button>
                                <?php endif; ?>
                                <?php if ($dueSoonNotifCount > 0): ?>
                                <button type="button" class="notif-tab-btn" onclick="filterNotifTab('due_soon', this)">⚠️ Mendekati (<?= $dueSoonNotifCount ?>)</button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="notif-dropdown-body">
                            <?php if (empty($headerNotifs)): ?>
                            <div class="notif-empty-state">
                                <div style="font-size:24px;margin-bottom:6px;">🎉</div>
                                <strong>Semua Jadwal Terpenuhi</strong>
                                <div style="font-size:12px;color:#64748b;margin-top:2px;">Tidak ada jadwal evaluasi yang terlewat atau mendekati tenggat.</div>
                            </div>
                            <?php else: ?>
                            <div class="notif-items-list" id="notifItemsList">
                                <?php foreach ($headerNotifs as $n): ?>
                                <a href="<?= h($n['link']) ?>" class="notif-item" data-type="<?= $n['type'] ?>">
                                    <div class="notif-item-icon <?= $n['type'] === 'overdue' ? 'icon-danger' : 'icon-warning' ?>">
                                        <?= $n['type'] === 'overdue' ? '🚨' : '⚠️' ?>
                                    </div>
                                    <div class="notif-item-content">
                                        <div class="notif-item-header">
                                            <span class="notif-item-kode"><?= h($n['kode']) ?></span>
                                            <span class="badge notif-item-badge <?= $n['type'] === 'overdue' ? 'badge-danger' : 'badge-warning' ?>"><?= h($n['siklus']) ?></span>
                                        </div>
                                        <div class="notif-item-title"><?= h($n['nama_mitra']) ?></div>
                                        <div class="notif-item-sub">
                                            <span>Target: <strong><?= formatTanggal($n['target_tgl']) ?></strong></span>
                                            <span class="notif-diff-pill <?= $n['type'] === 'overdue' ? 'diff-danger' : 'diff-warning' ?>">
                                                <?= $n['type'] === 'overdue' ? 'Terlewat ' . abs($n['sisa_hari']) . ' hari lalu' : ($n['sisa_hari'] >= 0 ? $n['sisa_hari'] . ' hari lagi' : abs($n['sisa_hari']) . ' hari lalu') ?>
                                            </span>
                                        </div>
                                    </div>
                                </a>
                                <?php endforeach; ?>
                                <div id="notifEmptyFilter" style="display:none;padding:24px;text-align:center;color:#64748b;font-size:12.5px;">
                                    Tidak ada notifikasi untuk kategori ini.
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="notif-dropdown-footer">
                            <a href="dashboard.php">Lihat di Dashboard &rarr;</a>
                            <a href="early_warning.php">Early Warning &rarr;</a>
                        </div>
                    </div>
                </div>

                <!-- User Menu Profile Button & Pop-up Dropdown -->
                <div class="header-user-wrap" id="headerUserWrap">
                    <button type="button" class="header-user-btn" id="headerUserBtn" onclick="toggleUserDropdown(event)" aria-haspopup="true" aria-expanded="false" title="Menu Akun: <?= h($user['nama']) ?>">
                        <div class="user-avatar" id="headerUserAvatarBox">
                            <?php if (!empty($userPhotoUrl)): ?>
                                <img src="<?= h($userPhotoUrl) ?>" alt="<?= h($user['nama']) ?>" class="avatar-photo" id="headerUserAvatarImg">
                            <?php else: ?>
                                <span id="headerUserInitials"><?= h($initials) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="user-info">
                            <div class="user-name" id="headerUserNameText"><?= h($user['nama']) ?></div>
                            <div class="user-role"><?= h(ucfirst($user['role'])) ?></div>
                        </div>
                        <span class="user-dropdown-arrow">▾</span>
                    </button>

                    <!-- User Pop-up Window -->
                    <div class="user-dropdown-popup" id="userDropdownPopup" onclick="event.stopPropagation()">
                        <div class="udp-header">
                            <div class="udp-avatar-wrap" onclick="openProfileEditModal()" title="Klik untuk ubah foto &amp; nama">
                                <div class="udp-avatar" id="popupUserAvatarBox">
                                    <?php if (!empty($userPhotoUrl)): ?>
                                        <img src="<?= h($userPhotoUrl) ?>" alt="<?= h($user['nama']) ?>" class="avatar-photo" id="popupUserAvatarImg">
                                    <?php else: ?>
                                        <span id="popupUserInitials"><?= h($initials) ?></span>
                                    <?php endif; ?>
                                </div>
                                <span class="udp-avatar-badge" title="Ganti Foto">
                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#334155" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path>
                                        <circle cx="12" cy="13" r="4"></circle>
                                    </svg>
                                </span>
                            </div>
                            <div class="udp-user-details">
                                <div class="udp-name" id="popupUserNameText"><?= h($user['nama']) ?></div>
                                <div class="udp-meta">
                                    <span class="badge badge-primary" style="font-size:10px;"><?= h(ucfirst($user['role'])) ?></span>
                                    <span class="udp-username">@<?= h($user['username'] ?? '') ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="udp-divider"></div>
                        <div class="udp-menu">
                            <button type="button" class="udp-menu-item" onclick="openProfileEditModal()">
                                <span class="udp-item-icon icon-profile">
                                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                    </svg>
                                </span>
                                <div class="udp-item-text">
                                    <div class="udp-item-title">Edit Profil</div>
                                    <div class="udp-item-desc">Ubah nama &amp; foto profil</div>
                                </div>
                            </button>
                            <button type="button" class="udp-menu-item" onclick="confirmUserAction('switch')">
                                <span class="udp-item-icon icon-switch">
                                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="23 4 23 10 17 10"></polyline>
                                        <polyline points="1 20 1 14 7 14"></polyline>
                                        <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                                    </svg>
                                </span>
                                <div class="udp-item-text">
                                    <div class="udp-item-title">Ganti Akun</div>
                                    <div class="udp-item-desc">Masuk dengan akun pengguna lain</div>
                                </div>
                            </button>
                            <button type="button" class="udp-menu-item udp-item-danger" onclick="confirmUserAction('logout')">
                                <span class="udp-item-icon icon-logout">
                                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                                        <polyline points="16 17 21 12 16 7"></polyline>
                                        <line x1="21" y1="12" x2="9" y2="12"></line>
                                    </svg>
                                </span>
                                <div class="udp-item-text">
                                    <div class="udp-item-title">Log Out</div>
                                    <div class="udp-item-desc">Keluar dari sesi saat ini</div>
                                </div>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Hidden Form for Logout/Switch Action -->
        <form id="userActionForm" method="POST" action="logout.php" style="display:none;">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="userActionInput" value="logout">
        </form>

        <!-- Confirmation Modal (IYA / TIDAK) -->
        <div id="userConfirmModal" class="user-confirm-modal-overlay" style="display:none;" onclick="if(event.target===this) closeUserConfirmModal();">
            <div class="user-confirm-modal-box">
                <div class="ucm-icon-wrap" id="ucmIconWrap">
                    <span id="ucmIcon" style="font-size:28px;">⚠️</span>
                </div>
                <h3 id="ucmTitle" style="margin:0 0 8px 0;font-size:16px;font-weight:700;color:#0f172a;text-align:center;">Konfirmasi</h3>
                <p id="ucmMessage" style="margin:0 0 20px 0;font-size:13px;line-height:1.55;color:#475569;text-align:center;">
                    Apakah Anda yakin?
                </p>
                <div class="ucm-actions">
                    <button type="button" class="btn-ucm-cancel" onclick="closeUserConfirmModal()">
                        TIDAK, BATAL
                    </button>
                    <button type="button" class="btn-ucm-confirm" id="ucmConfirmBtn">
                        IYA, YAKIN
                    </button>
                </div>
            </div>
        </div>

        <!-- Modal Edit Profil (Ubah Nama & Foto) -->
        <div id="profileEditModal" class="profile-modal-overlay" style="display:none;" onclick="if(event.target===this) closeProfileEditModal();">
            <div class="profile-modal-box">
                <div class="pem-header">
                    <div class="pem-header-title">
                        <span class="pem-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                        </span>
                        <h3>Edit Profil Pengguna</h3>
                    </div>
                    <button type="button" class="pem-close-btn" onclick="closeProfileEditModal()" title="Tutup">&times;</button>
                </div>

                <form id="profileEditForm" onsubmit="submitProfileEdit(event)" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="remove_foto" id="pemRemoveFotoInput" value="0">

                    <div class="pem-body">
                        <div id="pemAlertBox" class="pem-alert" style="display:none;"></div>

                        <!-- Foto Profil Section -->
                        <div class="pem-avatar-section">
                            <div class="pem-avatar-preview-wrap">
                                <div class="pem-avatar-preview" id="pemAvatarPreviewBox">
                                    <?php if (!empty($userPhotoUrl)): ?>
                                        <img src="<?= h($userPhotoUrl) ?>" alt="Foto Profil" id="pemPreviewImg" class="pem-preview-img">
                                    <?php else: ?>
                                        <span id="pemPreviewInitials"><?= h($initials) ?></span>
                                    <?php endif; ?>
                                </div>
                                <input type="file" id="pemPhotoInput" name="foto" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="handleProfilePhotoSelect(event)">
                            </div>

                            <div class="pem-avatar-controls">
                                <div class="pem-avatar-actions">
                                    <button type="button" class="btn-pem-secondary" onclick="document.getElementById('pemPhotoInput').click()">
                                        📁 Unggah Foto
                                    </button>
                                    <button type="button" class="btn-pem-danger" id="pemRemovePhotoBtn" onclick="handleRemovePhotoClick()" style="<?= !empty($userPhotoUrl) ? '' : 'display:none;' ?>">
                                        🗑️ Hapus Foto
                                    </button>
                                </div>
                                <div class="pem-avatar-hint">
                                    Format JPG, PNG, atau WebP. Maksimal 3 MB. Disarankan rasio kotak 1:1.
                                </div>
                            </div>
                        </div>

                        <!-- Nama Lengkap Field -->
                        <div class="pem-form-group">
                            <label for="pemNamaInput">Nama Lengkap <span style="color:#ef4444;">*</span></label>
                            <input type="text" id="pemNamaInput" name="nama" class="pem-input" value="<?= h($user['nama']) ?>" maxlength="150" required placeholder="Masukkan nama lengkap Anda">
                        </div>

                        <!-- Info Read-Only: Username & Role -->
                        <div class="pem-info-row">
                            <div class="pem-info-item">
                                <span class="pem-info-label">Username</span>
                                <span class="pem-info-val">@<?= h($user['username']) ?></span>
                            </div>
                            <div class="pem-info-item">
                                <span class="pem-info-label">Role Akses</span>
                                <span class="badge badge-primary" style="font-size:11px;"><?= h(ucfirst($user['role'])) ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="pem-footer">
                        <button type="button" class="btn-pem-cancel" onclick="closeProfileEditModal()">
                            Batal
                        </button>
                        <button type="submit" class="btn-pem-submit" id="pemSubmitBtn">
                            Simpan Perubahan
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <script>
        function toggleMobileNav() {
            var sidebar = document.querySelector('.sidebar');
            var backdrop = document.getElementById('mobileNavBackdrop');
            if (sidebar) sidebar.classList.toggle('mobile-open');
            if (backdrop) backdrop.classList.toggle('active');
        }
        function toggleNotifDropdown(e) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            var userWrap = document.getElementById('headerUserWrap');
            if (userWrap) userWrap.classList.remove('active');

            var wrap = document.getElementById('headerNotifWrap');
            if (!wrap) return;
            wrap.classList.toggle('active');
        }
        function toggleUserDropdown(e) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            var notifWrap = document.getElementById('headerNotifWrap');
            if (notifWrap) notifWrap.classList.remove('active');

            var userWrap = document.getElementById('headerUserWrap');
            if (!userWrap) return;
            userWrap.classList.toggle('active');
        }
        function confirmUserAction(actionType) {
            var userWrap = document.getElementById('headerUserWrap');
            if (userWrap) userWrap.classList.remove('active');

            var modal = document.getElementById('userConfirmModal');
            var iconWrap = document.getElementById('ucmIconWrap');
            var iconEl = document.getElementById('ucmIcon');
            var titleEl = document.getElementById('ucmTitle');
            var msgEl = document.getElementById('ucmMessage');
            var confirmBtn = document.getElementById('ucmConfirmBtn');
            var actionInput = document.getElementById('userActionInput');

            if (!modal) return;

            if (actionType === 'switch') {
                iconWrap.className = 'ucm-icon-wrap icon-switch-bg';
                iconEl.innerHTML = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>';
                titleEl.textContent = 'Konfirmasi Ganti Akun';
                msgEl.innerHTML = 'Apakah Anda yakin ingin <strong>Ganti Akun</strong>?<br><span style="font-size:12px;color:#64748b;display:inline-block;margin-top:4px;">Sesi akun saat ini akan diakhiri dan Anda akan diarahkan ke halaman login untuk memilih akun lain.</span>';
                confirmBtn.className = 'btn-ucm-confirm btn-switch-confirm';
                confirmBtn.textContent = 'IYA, GANTI AKUN';
                actionInput.value = 'switch';
            } else {
                iconWrap.className = 'ucm-icon-wrap icon-logout-bg';
                iconEl.innerHTML = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>';
                titleEl.textContent = 'Konfirmasi Keluar / Log Out';
                msgEl.innerHTML = 'Apakah Anda yakin ingin <strong>Keluar / Log Out</strong>?<br><span style="font-size:12px;color:#64748b;display:inline-block;margin-top:4px;">Sesi Anda saat ini akan diakhiri dan Anda harus masuk kembali untuk mengakses sistem.</span>';
                confirmBtn.className = 'btn-ucm-confirm btn-logout-confirm';
                confirmBtn.textContent = 'IYA, KELUAR';
                actionInput.value = 'logout';
            }

            confirmBtn.onclick = function() {
                document.getElementById('userActionForm').submit();
            };

            modal.style.display = 'flex';
        }
        function closeUserConfirmModal() {
            var modal = document.getElementById('userConfirmModal');
            if (modal) modal.style.display = 'none';
        }
        function filterNotifTab(type, btn) {
            var items = document.querySelectorAll('#notifItemsList .notif-item');
            var tabs = document.querySelectorAll('.notif-tab-btn');
            tabs.forEach(function(t) { t.classList.remove('active'); });
            if (btn) btn.classList.add('active');

            var visibleCount = 0;
            items.forEach(function(item) {
                if (type === 'all' || item.getAttribute('data-type') === type) {
                    item.style.display = 'flex';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            });

            var empty = document.getElementById('notifEmptyFilter');
            if (empty) {
                empty.style.display = visibleCount === 0 ? 'block' : 'none';
            }
        }
        document.addEventListener('click', function(e) {
            var notifWrap = document.getElementById('headerNotifWrap');
            if (notifWrap && notifWrap.classList.contains('active')) {
                if (!notifWrap.contains(e.target)) {
                    notifWrap.classList.remove('active');
                }
            }
            var userWrap = document.getElementById('headerUserWrap');
            if (userWrap && userWrap.classList.contains('active')) {
                if (!userWrap.contains(e.target)) {
                    userWrap.classList.remove('active');
                }
            }
        });
        function openProfileEditModal() {
            var userWrap = document.getElementById('headerUserWrap');
            if (userWrap) userWrap.classList.remove('active');

            var modal = document.getElementById('profileEditModal');
            if (!modal) return;

            var alertBox = document.getElementById('pemAlertBox');
            if (alertBox) alertBox.style.display = 'none';

            var photoInput = document.getElementById('pemPhotoInput');
            if (photoInput) photoInput.value = '';

            var removeInput = document.getElementById('pemRemoveFotoInput');
            if (removeInput) removeInput.value = '0';

            modal.style.display = 'flex';
            var namaInput = document.getElementById('pemNamaInput');
            if (namaInput) {
                namaInput.focus();
                namaInput.select();
            }
        }

        function closeProfileEditModal() {
            var modal = document.getElementById('profileEditModal');
            if (modal) modal.style.display = 'none';
        }

        function handleProfilePhotoSelect(e) {
            var file = e.target.files && e.target.files[0];
            if (!file) return;

            if (file.size > 3 * 1024 * 1024) {
                showProfileModalAlert('Ukuran file terlalu besar (maksimal 3 MB).', 'error');
                e.target.value = '';
                return;
            }

            if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
                showProfileModalAlert('Format file harus JPG, PNG, atau WebP.', 'error');
                e.target.value = '';
                return;
            }

            var reader = new FileReader();
            reader.onload = function(evt) {
                var box = document.getElementById('pemAvatarPreviewBox');
                if (box) {
                    box.innerHTML = '<img src="' + evt.target.result + '" alt="Preview Foto" class="pem-preview-img">';
                }
                var removeBtn = document.getElementById('pemRemovePhotoBtn');
                if (removeBtn) removeBtn.style.display = 'inline-block';
                var removeInput = document.getElementById('pemRemoveFotoInput');
                if (removeInput) removeInput.value = '0';
            };
            reader.readAsDataURL(file);
        }

        function handleRemovePhotoClick() {
            var photoInput = document.getElementById('pemPhotoInput');
            if (photoInput) photoInput.value = '';

            var removeInput = document.getElementById('pemRemoveFotoInput');
            if (removeInput) removeInput.value = '1';

            var box = document.getElementById('pemAvatarPreviewBox');
            var namaInput = document.getElementById('pemNamaInput');
            var currentName = (namaInput ? namaInput.value : '').trim();
            var parts = currentName.split(/\s+/);
            var inits = (parts[0] ? parts[0][0] : 'U').toUpperCase();
            if (parts.length > 1 && parts[parts.length - 1]) {
                inits += parts[parts.length - 1][0].toUpperCase();
            }

            if (box) {
                box.innerHTML = '<span id="pemPreviewInitials">' + inits + '</span>';
            }

            var removeBtn = document.getElementById('pemRemovePhotoBtn');
            if (removeBtn) removeBtn.style.display = 'none';
        }

        function showProfileModalAlert(msg, type) {
            var alertBox = document.getElementById('pemAlertBox');
            if (!alertBox) return;
            alertBox.className = 'pem-alert pem-alert-' + (type === 'success' ? 'success' : 'error');
            alertBox.textContent = msg;
            alertBox.style.display = 'block';
        }

        function submitProfileEdit(e) {
            e.preventDefault();
            var form = document.getElementById('profileEditForm');
            var btn = document.getElementById('pemSubmitBtn');
            if (!form || !btn) return;

            var namaInput = document.getElementById('pemNamaInput');
            var nama = namaInput ? namaInput.value.trim() : '';
            if (!nama) {
                showProfileModalAlert('Nama lengkap wajib diisi.', 'error');
                if (namaInput) namaInput.focus();
                return;
            }

            btn.disabled = true;
            var originalText = btn.textContent;
            btn.textContent = 'Menyimpan...';

            var formData = new FormData(form);

            fetch('profile_update.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                btn.disabled = false;
                btn.textContent = originalText;

                if (data && data.success) {
                    showProfileModalAlert(data.message || 'Profil berhasil diperbarui!', 'success');

                    var headerName = document.getElementById('headerUserNameText');
                    if (headerName) headerName.textContent = data.nama;

                    var popupName = document.getElementById('popupUserNameText');
                    if (popupName) popupName.textContent = data.nama;

                    var headerAvatar = document.getElementById('headerUserAvatarBox');
                    var popupAvatar = document.getElementById('popupUserAvatarBox');

                    if (data.has_foto && data.foto) {
                        if (headerAvatar) {
                            headerAvatar.innerHTML = '<img src="' + data.foto + '" alt="' + data.nama + '" class="avatar-photo" id="headerUserAvatarImg">';
                        }
                        if (popupAvatar) {
                            popupAvatar.innerHTML = '<img src="' + data.foto + '" alt="' + data.nama + '" class="avatar-photo" id="popupUserAvatarImg">';
                        }
                    } else {
                        if (headerAvatar) {
                            headerAvatar.innerHTML = '<span id="headerUserInitials">' + data.initials + '</span>';
                        }
                        if (popupAvatar) {
                            popupAvatar.innerHTML = '<span id="popupUserInitials">' + data.initials + '</span>';
                        }
                    }

                    if (data.csrf_token) {
                        var csrfInputs = document.querySelectorAll('input[name="csrf_token"]');
                        csrfInputs.forEach(function(inp) { inp.value = data.csrf_token; });
                    }

                    setTimeout(function() {
                        closeProfileEditModal();
                    }, 800);
                } else {
                    showProfileModalAlert((data && data.error) ? data.error : 'Gagal memperbarui profil.', 'error');
                    if (data && data.csrf_token) {
                        var csrfInputs = document.querySelectorAll('input[name="csrf_token"]');
                        csrfInputs.forEach(function(inp) { inp.value = data.csrf_token; });
                    }
                }
            })
            .catch(function(err) {
                btn.disabled = false;
                btn.textContent = originalText;
                showProfileModalAlert('Terjadi gangguan jaringan atau server. Silakan coba lagi.', 'error');
            });
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                var notifWrap = document.getElementById('headerNotifWrap');
                if (notifWrap) notifWrap.classList.remove('active');
                var userWrap = document.getElementById('headerUserWrap');
                if (userWrap) userWrap.classList.remove('active');
                closeUserConfirmModal();
                closeProfileEditModal();
            }
        });
        </script>
        <div class="page-body">
<?php else: ?>
<div class="page-body">
<?php endif; ?>
