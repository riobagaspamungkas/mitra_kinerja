<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

// Penggabungan menu Manajemen Naskah ke Portofolio:
// Alihkan seluruh akses lama mitra_manage.php ke portofolio.php
$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
    header('Location: portofolio.php?action=edit&id=' . $id);
    exit;
}

if (($_GET['action'] ?? '') === 'add') {
    header('Location: portofolio.php?action=add');
    exit;
}

header('Location: portofolio.php');
exit;
