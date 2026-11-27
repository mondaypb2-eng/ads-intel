<?php
require __DIR__ . '/config.php';
require APP_ROOT . '/lib/layout.php';
set_time_limit(1800);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Evaluator::runAll(!empty($_POST['ai']), ($_POST['report_mode'] ?? '') === 'claude' ? 'claude' : 'template');
    header('Location: eval.php?done=1');
    exit;
}
$last = is_file(RUNS_DIR . '/eval-latest.json') ? json_decode(file_get_contents(RUNS_DIR . '/eval-latest.json'), true) : null;
$ai = Llm::available();
layout_head('Eval', 'eval.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
  <div><h1 class="h3 fw-bold mb-1"><i class="bi bi-clipboard-check me-2"></i>โจทย์ฝึกฝน &amp; จุดวัดผล (Eval)</h1>
    <div class="d-flex flex-wrap gap-2 small">
      <span class="badge text-bg-light border"><i class="bi bi-braces me-1"></i>Valid Schema 100%</span>
      <span class="badge text-bg-light border"><i class="bi bi-calculator me-1"></i>ROAS/CPA ตรงค่าที่คำนวณมือ</span>
      <span class="badge text-bg-light border"><i class="bi bi-aspect-ratio me-1"></i>ไม่ล้นกรอบ 1080×1350</span>
    </div></div>
  <form method="post" class="card card-body py-2 px-3 flex-row align-items-center gap-3" id="evalForm">
    <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" name="ai" value="1" id="eai" <?= $ai ? '' : 'disabled' ?>><label class="form-check-label small" for="eai">ใช้ AI (<?= h(Llm::label()) ?>)</label></div>
    <select name="report_mode" class="form-select form-select-sm w-auto"><option value="template">รายงาน: เทมเพลต</option><option value="claude" <?= $ai ? '' : 'disabled' ?>>รายงาน: Claude HTML</option></select>
    <button class="btn btn-primary btn-sm px-3" id="runBtn"><i class="bi bi-play-fill me-1"></i>รัน Eval</button>
  </form>
</div>

<?php if ($last): $s = $last['summary']; $all = $s['passed'] === $s['cases']; ?>
  <div class="card mb-4 border-start border-5 border-<?= $all ? 'success' : 'danger' ?>"><div class="card-body d-flex flex-wrap align-items-center gap-4">
    <div class="display-5 fw-bold text-<?= $all ? 'success' : 'danger' ?>"><?= $s['passed'] ?>/<?= $s['cases'] ?></div>
    <div class="flex-grow-1">
      <div class="fw-semibold"><?= $all ? 'ผ่านทุกเคส' : 'มีเคสที่ไม่ผ่าน' ?></div>
      <div class="small text-body-secondary"><?= $s['ai'] ? 'ใช้ AI ' . h($s['engine'] ?? '') : 'โหมดกฎ' ?> · รายงาน <?= h($s['report_mode']) ?> · รันเมื่อ <?= h(date('j M Y H:i:s', strtotime($s['at']))) ?></div>
      <div class="progress mt-2" style="height:8px;max-width:420px"><div class="progress-bar bg-<?= $all ? 'success' : 'danger' ?>" style="width:<?= round($s['passed'] / max(1, $s['cases']) * 100) ?>%"></div></div>
    </div>
  </div></div>

  <div class="accordion" id="evalAcc">
  <?php $i = 0; foreach ($last['cases'] as $k => $c): $i++;
      $okN = count(array_filter($c['checks'], fn($x) => $x['ok'])); ?>
    <div class="accordion-item">
      <h2 class="accordion-header"><button class="accordion-button <?= $c['pass'] ? 'collapsed' : '' ?> gap-3" type="button" data-bs-toggle="collapse" data-bs-target="#e<?= $i ?>">
        <span class="badge <?= $c['pass'] ? 'text-bg-success' : 'text-bg-danger' ?>"><?= $c['pass'] ? 'PASS' : 'FAIL' ?></span>
        <span class="flex-grow-1"><span class="fw-semibold"><?= h($c['title']) ?></span> <code class="small ms-1"><?= h($k) ?></code></span>
        <span class="small text-body-secondary me-2"><?= $okN ?>/<?= count($c['checks']) ?> checks</span>
      </button></h2>
      <div id="e<?= $i ?>" class="accordion-collapse collapse <?= $c['pass'] ? '' : 'show' ?>"><div class="accordion-body">
        <div class="row g-4">
          <div class="col-md-9"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead><tr><th style="width:30px"></th><th style="width:90px">กลุ่ม</th><th>ตรวจ</th><th class="text-end">รายละเอียด</th></tr></thead>
            <tbody><?php foreach ($c['checks'] as $ch): ?>
              <tr class="<?= $ch['ok'] ? '' : 'table-danger' ?>"><td><i class="bi bi-<?= $ch['ok'] ? 'check-circle-fill text-success' : 'x-circle-fill text-danger' ?>"></i></td>
                <td><span class="badge text-bg-light border fw-normal"><?= h($ch['group']) ?></span></td><td><?= h($ch['name']) ?></td><td class="text-end small text-body-secondary"><?= h($ch['detail']) ?></td></tr>
            <?php endforeach; ?></tbody></table></div></div>
          <div class="col-md-3">
            <?php if (is_file(RUNS_DIR . "/{$c['id']}/report.png")): ?>
              <a href="view.php?id=<?= h($c['id']) ?>"><img src="runs/<?= h($c['id']) ?>/report.png?<?= filemtime(RUNS_DIR . "/{$c['id']}/report.png") ?>" class="report-img" alt=""></a>
              <a href="view.php?id=<?= h($c['id']) ?>" class="btn btn-sm btn-outline-primary w-100 mt-2">ดูผลเต็ม</a>
            <?php endif; ?>
          </div>
        </div>
      </div></div>
    </div>
  <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="card"><div class="card-body text-center py-5 text-body-secondary"><i class="bi bi-clipboard fs-1 d-block mb-2"></i>ยังไม่เคยรัน — กดปุ่ม "รัน Eval"</div></div>
<?php endif; ?>
<?php layout_foot('<script>document.getElementById("evalForm").addEventListener("submit",()=>{const b=document.getElementById("runBtn");b.disabled=true;b.innerHTML=\'<span class="spinner-border spinner-border-sm me-1"></span>กำลังรัน…\'})</script>');
