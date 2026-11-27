<?php
function layout_head(string $title, string $active = ''): void
{
    $ai = Llm::available();
    $nav = [
        'index.php' => ['bi-lightning-charge', 'วิเคราะห์'],
        'history.php' => ['bi-clock-history', 'ประวัติ'],
        'eval.php' => ['bi-clipboard-check', 'Eval'],
        'prompts.php' => ['bi-chat-square-text', 'Prompts'],
        'examples/' => ['bi-easel', 'ตัวอย่าง'],
        'docs.html' => ['bi-book', 'คู่มือ'],
        'settings.php' => ['bi-gear', 'ตั้งค่า'],
    ];
    ?><!doctype html>
<html lang="th">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> · Ads Intelligence</title>
<link rel="stylesheet" href="assets/bootstrap.min.css">
<link rel="stylesheet" href="assets/bootstrap-icons.min.css">
<link rel="stylesheet" href="assets/app.css?v=2">
<script>try{const t=localStorage.getItem('theme');if(t)document.documentElement.setAttribute('data-bs-theme',t)}catch(e){}</script>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark app-nav">
  <div class="container-xl">
    <a class="navbar-brand d-flex align-items-center gap-2" href="index.php"><i class="bi bi-bar-chart-line-fill text-info"></i> Ads Intelligence</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto gap-lg-1">
        <?php foreach ($nav as $href => [$icon, $label]): ?>
          <li class="nav-item"><a class="nav-link <?= $active === $href ? 'active' : '' ?>" href="<?= $href ?>"><i class="bi <?= $icon ?> me-1"></i><?= $label ?></a></li>
        <?php endforeach; ?>
      </ul>
      <div class="d-flex align-items-center gap-2">
        <?php if ($ai): ?>
          <a href="settings.php" class="badge rounded-pill text-decoration-none <?= Llm::isLocal() ? 'text-bg-info' : 'text-bg-success' ?>"><i class="bi <?= Llm::isLocal() ? 'bi-pc-display' : 'bi-stars' ?> me-1"></i><?= h(Llm::label()) ?></a>
        <?php else: ?>
          <a href="settings.php" class="badge rounded-pill text-bg-warning text-decoration-none" title="<?= h(Llm::problem()) ?>"><i class="bi bi-exclamation-triangle me-1"></i>โหมดกฎ (<?= h(Llm::problem()) ?>)</a>
        <?php endif; ?>
        <button class="btn btn-sm btn-outline-light border-0" id="themeBtn" title="สลับโหมดสว่าง/มืด"><i class="bi bi-circle-half"></i></button>
      </div>
    </div>
  </div>
</nav>
<main class="container-xl py-4">
<?php
}

function layout_foot(string $extraJs = ''): void
{
    ?>
</main>
<footer class="container-xl pb-4 text-center small text-body-secondary">Multi-Channel Ads Intelligence &amp; Visual Summary Engine</footer>
<script src="assets/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('themeBtn').onclick=()=>{const r=document.documentElement,n=r.getAttribute('data-bs-theme')==='dark'?'light':'dark';r.setAttribute('data-bs-theme',n);try{localStorage.setItem('theme',n)}catch(e){}};
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(e=>new bootstrap.Tooltip(e));
</script>
<?= $extraJs ?>
</body></html>
<?php
}

function status_badge(string $s, string $action = ''): string
{
    $cls = ['green' => 'text-bg-success', 'yellow' => 'text-bg-warning', 'red' => 'text-bg-danger'][$s] ?? 'text-bg-secondary';
    $icon = ['scale' => 'bi-graph-up-arrow', 'optimize' => 'bi-tools', 'pause' => 'bi-pause-circle', 'review' => 'bi-search'][$action] ?? 'bi-circle';
    $txt = ['scale' => 'ขยายงบ', 'optimize' => 'ปรับปรุง', 'pause' => 'หยุด/ลดงบ', 'review' => 'ตรวจสอบ'][$action] ?? $s;
    return '<span class="badge rounded-pill badge-status ' . $cls . '"><i class="bi ' . $icon . ' me-1"></i>' . $txt . '</span>';
}

function platform_icon(string $p): string
{
    return ['Meta' => '<i class="bi bi-meta text-primary"></i>', 'Google' => '<i class="bi bi-google text-success"></i>',
        'TikTok' => '<i class="bi bi-tiktok"></i>'][$p] ?? '<i class="bi bi-globe"></i>';
}

function fmt($v, int $dp = 2): string
{
    return $v === null ? '—' : number_format($v, $dp);
}
