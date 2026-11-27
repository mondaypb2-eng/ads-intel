<?php
require __DIR__ . '/config.php';
require APP_ROOT . '/lib/layout.php';
set_time_limit(900);
ignore_user_abort(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }

$files = [];
if (!empty($_FILES['files']['name'][0])) {
    foreach ($_FILES['files']['name'] as $i => $name) {
        if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ($_FILES['files']['size'][$i] > 20 * 1024 * 1024) continue;
        $files[] = ['name' => basename($name), 'content' => file_get_contents($_FILES['files']['tmp_name'][$i])];
    }
}
if (!$files && !empty($_POST['use_samples'])) {
    foreach (['meta_ads_export.csv', 'google_ads_report.csv', 'tiktok_ads.json'] as $n) {
        $files[] = ['name' => $n, 'content' => file_get_contents(APP_ROOT . "/samples/$n")];
    }
}

$settings = app_settings();
foreach (['target_roas', 'breakeven_roas'] as $k) {
    if (isset($_POST[$k]) && is_numeric($_POST[$k]) && $_POST[$k] > 0) $settings[$k] = (float)$_POST[$k];
}

try {
    if (!$files) throw new RuntimeException('ไม่ได้เลือกไฟล์');
    $res = Pipeline::run($files, (string)($_POST['products'] ?? ''), [
        'ai' => !empty($_POST['ai']),
        'report_mode' => ($_POST['report_mode'] ?? '') === 'claude' ? 'claude' : 'template',
        'settings' => $settings,
    ]);
    header('Location: view.php?id=' . urlencode($res['id']));
} catch (Throwable $e) {
    layout_head('ผิดพลาด');
    echo '<div class="alert alert-danger"><h5 class="alert-heading"><i class="bi bi-x-octagon me-1"></i>วิเคราะห์ไม่สำเร็จ</h5><div>' . h($e->getMessage()) . '</div><hr><a href="index.php" class="btn btn-outline-danger btn-sm"><i class="bi bi-arrow-left me-1"></i>กลับ</a></div>';
    layout_foot();
}
