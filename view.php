<?php
require __DIR__ . '/config.php';
require APP_ROOT . '/lib/layout.php';
$r = Pipeline::load($_GET['id'] ?? '');
if (!$r) { header('Location: index.php'); exit; }
$id = $r['id'];
$cur = $r['settings']['base_currency'];
$t = $r['totals'];
$diag = $r['diagnostics'];
$cnt = array_count_values(array_column($diag['campaigns'], 'status')) + ['green' => 0, 'yellow' => 0, 'red' => 0];
$v = '?v=' . filemtime(RUNS_DIR . "/$id/result.json");
$fit = $r['report']['fit'];
$nba = $r['next_best_action'];
layout_head('ผลวิเคราะห์ ' . $id);
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small mb-2"><li class="breadcrumb-item"><a href="index.php">วิเคราะห์</a></li><li class="breadcrumb-item"><a href="history.php">ประวัติ</a></li><li class="breadcrumb-item active"><?= h($id) ?></li></ol></nav>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
  <div>
    <h1 class="h3 fw-bold mb-1">ผลวิเคราะห์ <?= $r['period'][0] ? '<span class="text-body-secondary fw-normal fs-5">' . h(date('j M', strtotime($r['period'][0])) . ' – ' . date('j M Y', strtotime($r['period'][1]))) . '</span>' : '' ?></h1>
    <div class="d-flex flex-wrap gap-2 small">
      <span class="badge text-bg-light border"><i class="bi bi-files me-1"></i><?= count($r['files']) ?> ไฟล์ · <?= $r['row_count'] ?> แถว</span>
      <span class="badge <?= $r['ai_used'] ? 'text-bg-primary' : 'text-bg-secondary' ?>"><i class="bi bi-<?= $r['ai_used'] ? 'stars' : 'gear' ?> me-1"></i><?= $r['ai_used'] ? h(str_contains((string)$r['model'], '·') ? $r['model'] : 'Claude · ' . $r['model']) : 'โหมดกฎ' ?></span>
      <span class="badge text-bg-light border"><i class="bi bi-stopwatch me-1"></i><?= h($r['timing']['total']) ?> วินาที</span>
      <?php if ($r['issues']): ?><span class="badge text-bg-warning"><i class="bi bi-exclamation-triangle me-1"></i><?= count($r['issues']) ?> ข้อสังเกต</span><?php endif; ?>
    </div>
  </div>
  <div class="btn-group">
    <?php if ($r['report']['png']): ?><a href="runs/<?= h($id) ?>/report.png" download="ads-report-<?= h($id) ?>.png" class="btn btn-primary"><i class="bi bi-download me-1"></i>PNG</a><?php endif; ?>
    <button type="button" class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">ไฟล์อื่น</button>
    <ul class="dropdown-menu dropdown-menu-end">
      <li><a class="dropdown-item" href="runs/<?= h($id) ?>/report.html" target="_blank"><i class="bi bi-filetype-html me-2"></i>รายงาน HTML</a></li>
      <li><a class="dropdown-item" href="runs/<?= h($id) ?>/normalized.json" target="_blank"><i class="bi bi-filetype-json me-2"></i>Normalized JSON</a></li>
      <li><a class="dropdown-item" href="runs/<?= h($id) ?>/result.json" target="_blank"><i class="bi bi-filetype-json me-2"></i>Result JSON</a></li>
    </ul>
  </div>
</div>

<div class="row g-3 mb-4">
  <?php foreach ([
      ['bi-wallet2', 'primary', 'งบที่ใช้', ReportRenderer::money($t['spend'], $cur), number_format($t['impressions']) . ' impressions'],
      ['bi-cash-stack', 'success', 'รายได้', ReportRenderer::money($t['revenue'], $cur), number_format($t['conversions']) . ' conversions'],
      ['bi-graph-up-arrow', 'info', 'ROAS', $t['roas'] === null ? '—' : number_format($t['roas'], 2) . 'x', 'เป้า ' . number_format($r['settings']['target_roas'], 1) . 'x'],
      ['bi-bullseye', 'warning', 'CPA', ReportRenderer::money($t['cpa'], $cur), 'CTR ' . fmt($t['ctr']) . '%'],
  ] as [$icon, $color, $label, $val, $sub]): ?>
    <div class="col-6 col-xl-3"><div class="card kpi h-100"><div class="card-body d-flex gap-3 align-items-center">
      <div class="icon bg-<?= $color ?> bg-opacity-10 text-<?= $color ?>"><i class="bi <?= $icon ?>"></i></div>
      <div><div class="label"><?= $label ?></div><div class="value"><?= h($val) ?></div><div class="small text-body-secondary"><?= h($sub) ?></div></div>
    </div></div></div>
  <?php endforeach; ?>
</div>

<ul class="nav nav-pills mb-3 gap-1" role="tablist">
  <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tOverview"><i class="bi bi-speedometer2 me-1"></i>ภาพรวม</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tDiag"><i class="bi bi-activity me-1"></i>วินิจฉัย <span class="badge text-bg-danger ms-1"><?= $cnt['red'] ?></span></button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tNba"><i class="bi bi-lightbulb me-1"></i>แคมเปญใหม่ <span class="badge text-bg-secondary ms-1"><?= count($nba['proposals']) ?></span></button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#tData"><i class="bi bi-table me-1"></i>ข้อมูล &amp; Mapping</button></li>
</ul>

<div class="row g-4">
  <div class="col-xl-8">
    <div class="tab-content">

      <!-- Overview -->
      <div class="tab-pane fade show active" id="tOverview">
        <div class="card mb-4"><div class="card-body d-flex gap-3">
          <i class="bi bi-chat-quote fs-3 text-primary"></i>
          <div><div class="fw-semibold mb-1">สรุปสำหรับผู้บริหาร</div><div><?= nl2br(h($diag['summary'])) ?></div></div>
        </div></div>
        <div class="row g-4">
          <div class="col-md-8"><div class="card h-100"><div class="card-header">งบ vs รายได้ แยกแพลตฟอร์ม</div>
            <div class="card-body"><div class="chart-box"><canvas id="platChart"></canvas></div></div></div></div>
          <div class="col-md-4"><div class="card h-100"><div class="card-header">สถานะแคมเปญ</div>
            <div class="card-body"><div class="chart-box" style="height:200px"><canvas id="statusChart"></canvas></div>
              <div class="d-flex justify-content-around small mt-2">
                <span><span class="dot dot-green me-1"></span>ขยาย <?= $cnt['green'] ?></span><span><span class="dot dot-yellow me-1"></span>ปรับ <?= $cnt['yellow'] ?></span><span><span class="dot dot-red me-1"></span>หยุด <?= $cnt['red'] ?></span>
              </div></div></div></div>
        </div>
        <div class="card mt-4"><div class="card-header">ผลรายแพลตฟอร์ม</div>
          <div class="table-responsive"><table class="table table-hover align-middle mb-0">
            <thead><tr><th>แพลตฟอร์ม</th><th class="num">งบ</th><th class="num">รายได้</th><th class="num">Conv.</th><th class="num">ROAS</th><th class="num">CPA</th><th class="num">CTR %</th></tr></thead>
            <tbody><?php foreach ($r['platforms'] as $p): ?>
              <tr><td class="fw-semibold"><?= platform_icon($p['platform']) ?> <?= h($p['platform']) ?></td><td class="num"><?= fmt($p['spend'], 0) ?></td><td class="num"><?= fmt($p['revenue'], 0) ?></td>
                <td class="num"><?= fmt($p['conversions'], 0) ?></td><td class="num fw-bold <?= ($p['roas'] ?? 0) >= $r['settings']['target_roas'] ? 'text-success' : (($p['roas'] ?? 0) < $r['settings']['breakeven_roas'] ? 'text-danger' : '') ?>"><?= fmt($p['roas']) ?></td>
                <td class="num"><?= fmt($p['cpa']) ?></td><td class="num"><?= fmt($p['ctr']) ?></td></tr>
            <?php endforeach; ?></tbody></table></div></div>
      </div>

      <!-- Diagnostics -->
      <div class="tab-pane fade" id="tDiag">
        <div class="d-flex flex-wrap gap-2 mb-3" id="filters">
          <button class="btn btn-sm btn-outline-dark active" data-f="all">ทั้งหมด (<?= count($r['campaigns']) ?>)</button>
          <button class="btn btn-sm btn-outline-danger" data-f="red"><span class="dot dot-red me-1"></span>หยุด/ลดงบ (<?= $cnt['red'] ?>)</button>
          <button class="btn btn-sm btn-outline-warning" data-f="yellow"><span class="dot dot-yellow me-1"></span>ปรับปรุง (<?= $cnt['yellow'] ?>)</button>
          <button class="btn btn-sm btn-outline-success" data-f="green"><span class="dot dot-green me-1"></span>ขยายงบ (<?= $cnt['green'] ?>)</button>
          <input type="search" id="q" class="form-control form-control-sm ms-auto" style="max-width:220px" placeholder="ค้นหาแคมเปญ…">
        </div>
        <div class="accordion" id="diagAcc">
        <?php foreach ($r['campaigns'] as $i => $c): $d = $diag['campaigns'][$c['id']]; ?>
          <div class="accordion-item diag-item" data-status="<?= $d['status'] ?>" data-name="<?= h(mb_strtolower($c['campaign_name'] . ' ' . $c['platform'])) ?>">
            <h2 class="accordion-header"><button class="accordion-button collapsed gap-3" type="button" data-bs-toggle="collapse" data-bs-target="#c<?= $i ?>">
              <span class="dot dot-<?= $d['status'] ?>"></span>
              <span class="flex-grow-1 text-truncate"><span class="fw-semibold"><?= h($c['campaign_name']) ?></span><br><span class="small text-body-secondary"><?= platform_icon($c['platform']) ?> <?= h($c['platform']) ?><?= $d['flags'] ? ' · ' . count($d['flags']) . ' สัญญาณ' : '' ?></span></span>
              <span class="d-none d-md-flex gap-4 small text-end me-2">
                <span><span class="text-body-secondary d-block">งบ</span><?= fmt($c['spend'], 0) ?></span>
                <span><span class="text-body-secondary d-block">ROAS</span><b><?= fmt($c['roas']) ?></b></span>
                <span><span class="text-body-secondary d-block">CPA</span><?= fmt($c['cpa']) ?></span>
              </span>
              <?= status_badge($d['status'], $d['action']) ?>
            </button></h2>
            <div id="c<?= $i ?>" class="accordion-collapse collapse"><div class="accordion-body">
              <div class="row g-2 small mb-3 text-center">
                <?php foreach (['Impr.' => fmt($c['impressions'], 0), 'Clicks' => fmt($c['clicks'], 0), 'CTR %' => fmt($c['ctr']), 'CPC' => fmt($c['cpc']), 'Conv.' => fmt($c['conversions'], 0), 'รายได้' => fmt($c['revenue'], 0), 'Freq.' => fmt($c['frequency'] ?? null)] as $k => $val): ?>
                  <div class="col"><div class="bg-body-tertiary rounded p-2"><div class="text-body-secondary"><?= $k ?></div><b><?= $val ?></b></div></div>
                <?php endforeach; ?>
              </div>
              <?php foreach ($d['flags'] as $f): ?>
                <div class="alert alert-<?= $f['level'] === 'critical' ? 'danger' : ($f['level'] === 'warning' ? 'warning' : 'secondary') ?> py-2 px-3 small mb-2"><i class="bi bi-<?= $f['level'] === 'critical' ? 'exclamation-octagon' : 'exclamation-triangle' ?> me-1"></i><code class="flag me-1"><?= h($f['code']) ?></code><?= h($f['text']) ?></div>
              <?php endforeach; ?>
              <p class="mb-2"><?= !empty($d['ai']) ? '<span class="badge text-bg-primary me-1"><i class="bi bi-stars"></i> Claude</span>' : '' ?><?= h($d['explanation']) ?></p>
              <div class="fw-semibold small mb-1">สิ่งที่ควรทำ</div>
              <ul class="mb-0 small"><?php foreach ($d['next_steps'] as $s): ?><li><?= h($s) ?></li><?php endforeach; ?></ul>
            </div></div>
          </div>
        <?php endforeach; ?>
        </div>
      </div>

      <!-- Next best action -->
      <div class="tab-pane fade" id="tNba">
        <?php if ($nba['gaps']): ?>
          <div class="card mb-3"><div class="card-body small">
            <div class="fw-semibold mb-2"><i class="bi bi-grid-3x3-gap me-1"></i>ช่องว่าง สินค้า × ช่องทาง <span class="badge text-bg-light border ms-1"><?= ['claude' => 'Claude', 'local' => 'LLM ในเครื่อง'][$nba['source']] ?? 'กฎ' ?></span></div>
            <div class="d-flex flex-wrap gap-2"><?php foreach ($nba['gaps'] as $g): ?>
              <span class="badge text-bg-light border fw-normal p-2"><b><?= h($g['product']) ?></b> → <?php foreach ($g['missing'] as $m): ?><?= platform_icon($m) ?> <?php endforeach; ?></span>
            <?php endforeach; ?></div>
          </div></div>
        <?php endif; ?>
        <div class="row g-3">
        <?php foreach ($nba['proposals'] as $p): $pc = ['high' => 'danger', 'medium' => 'warning', 'low' => 'secondary'][$p['priority']] ?? 'secondary'; ?>
          <div class="col-md-6"><div class="card h-100 border-top border-4 border-<?= $pc ?>">
            <div class="card-body vstack gap-2">
              <div class="d-flex justify-content-between align-items-start gap-2">
                <div><div class="fw-bold"><?= h($p['product']) ?></div><code class="small text-break"><?= h($p['campaign_name']) ?></code></div>
                <div class="text-end"><span class="badge text-bg-dark"><?= platform_icon($p['platform']) ?> <?= h($p['platform']) ?></span><br><span class="badge text-bg-<?= $pc ?> mt-1"><?= h($p['priority']) ?></span></div>
              </div>
              <div class="small"><i class="bi bi-flag me-1"></i><?= h($p['objective']) ?> · <b><?= h(ReportRenderer::money($p['daily_budget'], $cur)) ?>/วัน</b></div>
              <div class="bg-body-tertiary rounded p-2 small">
                <div><i class="bi bi-people me-1"></i>อายุ <?= h($p['audience']['age']) ?> · <?= h($p['audience']['gender']) ?> · <?= h($p['audience']['locations']) ?></div>
                <div class="mt-1"><?php foreach ($p['audience']['interests'] as $in): ?><span class="badge text-bg-light border fw-normal me-1 mb-1"><?= h($in) ?></span><?php endforeach; ?></div>
                <div class="text-body-secondary"><i class="bi bi-slash-circle me-1"></i><?= h($p['audience']['exclusions']) ?></div>
              </div>
              <ul class="list-unstyled small mb-0"><?php foreach ($p['headlines'] as $hl): ?><li class="mb-1"><i class="bi bi-chat-left-quote text-primary me-1"></i><?= h($hl) ?></li><?php endforeach; ?></ul>
              <div class="small text-body-secondary mt-auto"><?= h($p['rationale']) ?></div>
            </div></div></div>
        <?php endforeach; ?>
        <?php if (!$nba['proposals']): ?><div class="col-12"><div class="alert alert-secondary">ไม่มีข้อเสนอ: ยังไม่ได้ใส่ข้อมูลสินค้า หรือทุกสินค้ายิงครบทุกช่องทางแล้ว</div></div><?php endif; ?>
        </div>
      </div>

      <!-- Data -->
      <div class="tab-pane fade" id="tData">
        <div class="card mb-4"><div class="card-header d-flex justify-content-between">
          <span>การ map คอลัมน์</span>
          <span class="badge <?= $r['invalid_rows'] ? 'text-bg-danger' : 'text-bg-success' ?>"><i class="bi bi-check2-circle me-1"></i>Schema <?= $r['row_count'] - count($r['invalid_rows']) ?>/<?= $r['row_count'] ?> แถวผ่าน</span></div>
          <div class="list-group list-group-flush">
          <?php foreach ($r['mappings'] as $m): ?>
            <div class="list-group-item">
              <div class="d-flex flex-wrap justify-content-between gap-2 mb-2"><span class="fw-semibold"><i class="bi bi-file-earmark-text me-1"></i><?= h($m['file']) ?></span>
                <span class="small"><?= platform_icon($m['platform']) ?> <?= h($m['platform']) ?> · <?= h($m['currency'] ?: 'สกุลเงินตามแถว') ?> · <span class="badge <?= $m['source'] !== 'dictionary' ? 'text-bg-primary' : 'text-bg-secondary' ?>"><?= ['claude' => 'Claude', 'local' => 'LLM ในเครื่อง'][$m['source']] ?? 'พจนานุกรม' ?></span></span></div>
              <div class="d-flex flex-wrap gap-1"><?php foreach ($m['map'] as $k => $col): ?>
                <span class="badge mapping-chip <?= $col ? 'text-bg-success bg-opacity-10 text-success-emphasis border border-success-subtle' : 'text-bg-light text-body-secondary border' ?>"><?= h($k) ?> ← <?= $col ? h($col) : '—' ?></span>
              <?php endforeach; ?></div>
            </div>
          <?php endforeach; ?>
          </div></div>
        <?php if ($r['issues']): ?>
          <div class="card mb-4"><div class="card-header text-warning-emphasis"><i class="bi bi-exclamation-triangle me-1"></i>ข้อสังเกตด้านคุณภาพข้อมูล (<?= count($r['issues']) ?>)</div>
            <ul class="list-group list-group-flush small"><?php foreach ($r['issues'] as $i): ?><li class="list-group-item"><?= h($i) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <div class="card"><div class="card-header">รายแคมเปญ (normalized, หน่วย <?= h($cur) ?>)</div>
          <div class="table-responsive"><table class="table table-sm table-hover mb-0 small">
            <thead><tr><th>แคมเปญ</th><th>แพลตฟอร์ม</th><th class="num">Spend</th><th class="num">Impr.</th><th class="num">Clicks</th><th class="num">Conv.</th><th class="num">Revenue</th><th class="num">CTR</th><th class="num">CPC</th><th class="num">CPA</th><th class="num">ROAS</th></tr></thead>
            <tbody><?php foreach ($r['campaigns'] as $c): ?>
              <tr><td class="text-truncate" style="max-width:220px"><?= h($c['campaign_name']) ?></td><td><?= h($c['platform']) ?></td>
                <?php foreach ([['spend', 2], ['impressions', 0], ['clicks', 0], ['conversions', 2], ['revenue', 2], ['ctr', 2], ['cpc', 2], ['cpa', 2], ['roas', 2]] as [$k, $dp]): ?><td class="num"><?= fmt($c[$k], $dp) ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?></tbody></table></div></div>
      </div>
    </div>
  </div>

  <div class="col-xl-4">
    <div class="card sticky-lg">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-image me-1"></i>รายงานภาพ 1080×1350</span>
        <?php if ($fit): ?><span class="badge <?= $fit['fits'] ? 'text-bg-success' : 'text-bg-danger' ?>"><i class="bi bi-<?= $fit['fits'] ? 'check-lg' : 'x-lg' ?>"></i> <?= $fit['fits'] ? 'พอดีกรอบ' : 'ล้นกรอบ' ?></span><?php endif; ?>
      </div>
      <div class="card-body">
        <?php if ($r['report']['png']): ?>
          <a href="#" data-bs-toggle="modal" data-bs-target="#imgModal"><img src="runs/<?= h($id) ?>/report.png<?= $v ?>" class="report-img" alt="report"></a>
          <div class="d-flex justify-content-between align-items-center mt-3 small text-body-secondary">
            <span><?= $r['report']['mode'] === 'claude' ? '<i class="bi bi-stars"></i> ออกแบบโดย Claude' : 'เทมเพลตมาตรฐาน' ?></span>
            <a href="runs/<?= h($id) ?>/report.png" download="ads-report-<?= h($id) ?>.png" class="btn btn-sm btn-outline-primary"><i class="bi bi-download"></i> ดาวน์โหลด</a>
          </div>
        <?php else: ?>
          <div class="alert alert-warning small mb-0">สร้าง PNG ไม่สำเร็จ (ตรวจ path ของ Edge ในหน้าตั้งค่า) — <a href="runs/<?= h($id) ?>/report.html" target="_blank">เปิด HTML</a></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($r['report']['png']): ?>
<div class="modal fade" id="imgModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content bg-transparent border-0">
  <img src="runs/<?= h($id) ?>/report.png<?= $v ?>" class="report-img" alt="report full"></div></div></div>
<?php endif; ?>
<?php
$plat = array_map(fn($p) => ['p' => $p['platform'], 's' => $p['spend'], 'r' => $p['revenue'], 'roas' => $p['roas']], $r['platforms']);
$js = '<script src="assets/chart.umd.js"></script><script>
const plat=' . json_encode($plat) . ', cnt=' . json_encode([$cnt['green'], $cnt['yellow'], $cnt['red']]) . ';
const fmtK=v=>v>=1e6?(v/1e6).toFixed(2)+"M":v>=1e3?(v/1e3).toFixed(1)+"K":v.toFixed(0);
const css=getComputedStyle(document.body), txt=css.getPropertyValue("--bs-secondary-color").trim()||"#6c757d";
Chart.defaults.font.family=css.fontFamily; Chart.defaults.color=txt;
new Chart(platChart,{type:"bar",data:{labels:plat.map(p=>p.p+"  (ROAS "+(p.roas===null?"—":p.roas.toFixed(2))+")"),datasets:[
  {label:"งบ",data:plat.map(p=>p.s),backgroundColor:"#cbd5e1",borderRadius:6},
  {label:"รายได้",data:plat.map(p=>p.r),backgroundColor:"#4f46e5",borderRadius:6}]},
  options:{indexAxis:"y",maintainAspectRatio:false,plugins:{legend:{position:"bottom"},tooltip:{callbacks:{label:c=>c.dataset.label+": "+c.parsed.x.toLocaleString()}}},
  scales:{x:{ticks:{callback:fmtK},grid:{color:"rgba(128,128,128,.15)"}},y:{grid:{display:false}}}}});
new Chart(statusChart,{type:"doughnut",data:{labels:["ขยายงบ","ปรับปรุง","หยุด/ลดงบ"],datasets:[{data:cnt,backgroundColor:["#10b981","#f59e0b","#ef4444"],borderWidth:0}]},
  options:{maintainAspectRatio:false,cutout:"68%",plugins:{legend:{display:false}}}});
let cur="all";
function applyFilter(){const q=document.getElementById("q").value.toLowerCase();
  document.querySelectorAll(".diag-item").forEach(el=>{el.style.display=((cur==="all"||el.dataset.status===cur)&&el.dataset.name.includes(q))?"":"none"})}
document.querySelectorAll("#filters [data-f]").forEach(b=>b.onclick=()=>{cur=b.dataset.f;
  document.querySelectorAll("#filters [data-f]").forEach(x=>x.classList.toggle("active",x===b));applyFilter()});
document.getElementById("q").oninput=applyFilter;
</script>';
layout_foot($js);
