<?php
require __DIR__ . '/config.php';
require APP_ROOT . '/lib/layout.php';
layout_head('ประวัติ', 'history.php');
$runs = Pipeline::history(60);
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h1 class="h3 fw-bold mb-0"><i class="bi bi-clock-history me-2"></i>ประวัติการวิเคราะห์</h1>
  <a href="index.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>วิเคราะห์ใหม่</a>
</div>
<?php if (!$runs): ?>
  <div class="card"><div class="card-body text-center py-5 text-body-secondary"><i class="bi bi-inbox fs-1 d-block mb-2"></i>ยังไม่มีประวัติ</div></div>
<?php endif; ?>
<div class="row row-cols-2 row-cols-md-3 row-cols-xl-5 g-4">
<?php foreach ($runs as $r): $cnt = array_count_values(array_column($r['diagnostics']['campaigns'], 'status')) + ['green' => 0, 'yellow' => 0, 'red' => 0]; ?>
  <div class="col"><a href="view.php?id=<?= h($r['id']) ?>" class="card h-100 text-decoration-none text-body">
    <?php if ($r['report']['png']): ?><img src="runs/<?= h($r['id']) ?>/report.png" class="thumb" loading="lazy" alt=""><?php endif; ?>
    <div class="card-body small">
      <div class="fw-semibold"><?= h(date('j M Y H:i', strtotime($r['created']))) ?></div>
      <div class="text-body-secondary text-truncate"><?= h(implode(', ', $r['files'])) ?></div>
      <div class="d-flex justify-content-between align-items-center mt-2">
        <span>ROAS <b><?= fmt($r['totals']['roas']) ?></b></span>
        <span><span class="dot dot-green"></span> <?= $cnt['green'] ?> <span class="dot dot-yellow ms-1"></span> <?= $cnt['yellow'] ?> <span class="dot dot-red ms-1"></span> <?= $cnt['red'] ?></span>
      </div>
      <span class="badge mt-2 <?= $r['ai_used'] ? 'text-bg-primary' : 'text-bg-secondary' ?>"><?= $r['ai_used'] ? h($r['model'] ?? 'AI') : 'โหมดกฎ' ?></span>
    </div>
  </a></div>
<?php endforeach; ?>
</div>
<?php layout_foot();
