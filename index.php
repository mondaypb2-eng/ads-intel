<?php
require __DIR__ . '/config.php';
require APP_ROOT . '/lib/layout.php';
$s = app_settings();
$ai = Llm::available();
$sampleProducts = file_get_contents(APP_ROOT . '/samples/products.json');
$recent = Pipeline::history(3);
layout_head('วิเคราะห์', 'index.php');
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
  <div>
    <h1 class="h3 fw-bold mb-1">วิเคราะห์โฆษณาข้ามแพลตฟอร์ม</h1>
    <div class="text-body-secondary">อัปโหลดไฟล์ export จาก Meta, Google, TikTok · ชื่อคอลัมน์ต่างกันได้ ระบบ map ให้อัตโนมัติ</div>
  </div>
  <div class="d-flex gap-2">
    <span class="badge rounded-pill text-bg-light border"><i class="bi bi-meta text-primary"></i> Meta</span>
    <span class="badge rounded-pill text-bg-light border"><i class="bi bi-google text-success"></i> Google</span>
    <span class="badge rounded-pill text-bg-light border"><i class="bi bi-tiktok"></i> TikTok</span>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-8">
    <form id="f" action="run.php" method="post" enctype="multipart/form-data" class="vstack gap-4">

      <div class="card">
        <div class="card-header"><span class="step-num">1</span>ข้อมูลโฆษณา</div>
        <div class="card-body">
          <div class="form-check form-switch p-3 ps-5 rounded-3 bg-primary bg-opacity-10 mb-3">
            <input class="form-check-input" type="checkbox" role="switch" name="use_samples" value="1" id="useSamples" checked>
            <label class="form-check-label" for="useSamples"><b>ใช้ข้อมูลตัวอย่าง</b>
              <span class="text-body-secondary small d-block">3 ไฟล์ · 10 แคมเปญ · 14 วัน (Meta THB, Google USD, TikTok JSON) —
                <a href="samples/meta_ads_export.csv" download>Meta</a> · <a href="samples/google_ads_report.csv" download>Google</a> · <a href="samples/tiktok_ads.json" download>TikTok</a></span></label>
          </div>
          <div class="dropzone" id="drop" onclick="document.getElementById('files').click()">
            <input type="file" name="files[]" id="files" multiple accept=".csv,.json,.tsv,.txt" class="d-none">
            <i class="bi bi-cloud-arrow-up"></i>
            <div class="fw-semibold mt-2">ลากไฟล์มาวาง หรือคลิกเพื่อเลือก</div>
            <div class="small text-body-secondary">CSV / JSON · เลือกได้หลายไฟล์ · ไม่เกิน 20MB ต่อไฟล์</div>
          </div>
          <ul class="list-group list-group-flush mt-2" id="fileList"></ul>
        </div>
      </div>

      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span><span class="step-num">2</span>สินค้า / บริการ <span class="fw-normal text-body-secondary small">ใช้หาแคมเปญใหม่ที่ควรยิง</span></span>
          <div class="btn-group btn-group-sm">
            <button type="button" class="btn btn-outline-secondary" onclick="prod.value=sampleProducts"><i class="bi bi-arrow-counterclockwise"></i> ตัวอย่าง</button>
            <button type="button" class="btn btn-outline-secondary" onclick="prod.value=''"><i class="bi bi-x-lg"></i> ล้าง</button>
          </div>
        </div>
        <div class="card-body">
          <textarea name="products" id="prod" rows="8" class="form-control font-monospace small"><?= h($sampleProducts) ?></textarea>
          <div class="form-text">ใส่ JSON หรือบรรทัดละรายการ: <code>ชื่อ | ราคา | กลุ่มเป้าหมาย | Meta,Google,TikTok</code> (ช่องทางที่ยิงอยู่)</div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><span class="step-num">3</span>ตัวเลือก</div>
        <div class="card-body row g-3">
          <div class="col-sm-4"><div class="form-floating">
            <input name="target_roas" id="tr" type="number" step="0.1" min="0.1" value="<?= h($s['target_roas']) ?>" class="form-control"><label for="tr">ROAS เป้าหมาย</label></div></div>
          <div class="col-sm-4"><div class="form-floating">
            <input name="breakeven_roas" id="br" type="number" step="0.1" min="0.1" value="<?= h($s['breakeven_roas']) ?>" class="form-control"><label for="br">ROAS จุดคุ้มทุน</label></div></div>
          <div class="col-sm-4"><div class="form-floating">
            <select name="report_mode" id="rm" class="form-select">
              <option value="template">เทมเพลตมาตรฐาน</option>
              <option value="claude" <?= Llm::canDesignHtml() ? '' : 'disabled' ?>>Claude ออกแบบ HTML<?= Llm::isLocal() ? ' (เฉพาะ Claude API)' : '' ?></option>
            </select><label for="rm">รูปแบบรายงานภาพ</label></div></div>
          <div class="col-12">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" name="ai" value="1" id="aiSw" <?= $ai ? 'checked' : 'disabled' ?>>
              <label class="form-check-label" for="aiSw"><i class="bi bi-stars text-primary"></i> ใช้ AI ใน pipeline — <b><?= h(Llm::label()) ?></b> (map คอลัมน์ · อธิบายสาเหตุ · เสนอแคมเปญใหม่)</label>
              <?php if (!$ai): ?><a href="settings.php" class="ms-2 small"><?= h(Llm::problem()) ?></a><?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <button class="btn btn-primary btn-lg py-3 fw-bold shadow"><i class="bi bi-play-circle-fill me-2"></i>เริ่มวิเคราะห์ &amp; สร้างรายงาน</button>
    </form>
  </div>

  <div class="col-lg-4 vstack gap-4">
    <div class="card">
      <div class="card-header"><i class="bi bi-diagram-3 me-1 text-primary"></i>Prompt Pipeline</div>
      <div class="card-body">
        <ul class="timeline">
          <li><span class="n">1</span><b>Data Normalization</b><div class="small text-body-secondary">รวมทุกแพลตฟอร์มเป็น schema เดียว แปลงสกุลเงินเป็น <?= h($s['base_currency']) ?></div></li>
          <li><span class="n">2</span><b>Diagnostic &amp; Anomaly</b><div class="small text-body-secondary">CPA พุ่ง · Creative Fatigue · ไม่มี conversion</div></li>
          <li><span class="n">3</span><b>Next-Best-Action</b><div class="small text-body-secondary">สินค้า × ช่องทางที่ยังไม่ยิง → แคมเปญใหม่</div></li>
          <li><span class="n">4</span><b>Visual Report</b><div class="small text-body-secondary">HTML + Tailwind 1080×1350 → PNG</div></li>
        </ul>
      </div>
    </div>
    <div class="alert alert-info small mb-0"><i class="bi bi-shield-check me-1"></i><b>ตัวเลขแม่นยำ:</b> ROAS / CPA / CTR คำนวณด้วยโค้ดจากยอดรวมจริง AI ไม่ได้คิดเลข</div>
    <?php if ($recent): ?>
    <div class="card">
      <div class="card-header d-flex justify-content-between"><span><i class="bi bi-clock-history me-1"></i>ล่าสุด</span><a href="history.php" class="small fw-normal">ดูทั้งหมด</a></div>
      <div class="list-group list-group-flush">
        <?php foreach ($recent as $r): ?>
          <a href="view.php?id=<?= h($r['id']) ?>" class="list-group-item list-group-item-action d-flex gap-3 align-items-center">
            <?php if ($r['report']['png']): ?><img src="runs/<?= h($r['id']) ?>/report.png" width="40" class="rounded border"><?php endif; ?>
            <div class="small flex-grow-1"><div class="fw-semibold"><?= h(date('j M H:i', strtotime($r['created']))) ?></div>
              <div class="text-body-secondary">ROAS <?= fmt($r['totals']['roas']) ?> · <?= count($r['campaigns']) ?> แคมเปญ</div></div>
            <i class="bi bi-chevron-right text-body-secondary"></i></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="modal fade" id="loading" data-bs-backdrop="static" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content p-4">
    <div class="d-flex align-items-center gap-3 mb-3">
      <div class="spinner-border text-primary"></div>
      <div><div class="fw-bold fs-5">กำลังวิเคราะห์…</div><div class="small text-body-secondary" id="eta"></div></div>
    </div>
    <div class="progress mb-3" style="height:8px"><div class="progress-bar progress-bar-striped progress-bar-animated" id="bar" style="width:5%"></div></div>
    <ol class="list-unstyled mb-0" id="loadSteps">
      <li><i class="bi bi-circle me-2"></i>Normalize ข้อมูล</li>
      <li><i class="bi bi-circle me-2"></i>วิเคราะห์ความผิดปกติ</li>
      <li><i class="bi bi-circle me-2"></i>เสนอแคมเปญใหม่</li>
      <li><i class="bi bi-circle me-2"></i>สร้างรายงานภาพ &amp; PNG</li>
    </ol>
  </div></div>
</div>
<?php
$js = '<script>const LOCAL=' . (Llm::isLocal() ? 'true' : 'false') . ';</script>' . <<<'JS'
<script>
const sampleProducts = document.getElementById('prod').value;
const files = document.getElementById('files'), drop = document.getElementById('drop'), list = document.getElementById('fileList');
function showFiles(){
  list.innerHTML = '';
  [...files.files].forEach(f => {
    const li = document.createElement('li');
    li.className = 'list-group-item d-flex justify-content-between align-items-center px-1';
    const name = document.createElement('span'); name.innerHTML = '<i class="bi bi-file-earmark-spreadsheet text-primary me-2"></i>'; name.append(f.name);
    const size = document.createElement('span'); size.className = 'small text-body-secondary'; size.textContent = (f.size/1024).toFixed(1) + ' KB';
    li.append(name, size); list.append(li);
  });
  if (files.files.length) document.getElementById('useSamples').checked = false;
}
files.onchange = showFiles;
['dragover','dragenter'].forEach(e => drop.addEventListener(e, ev => { ev.preventDefault(); drop.classList.add('drag'); }));
['dragleave','drop'].forEach(e => drop.addEventListener(e, ev => { ev.preventDefault(); drop.classList.remove('drag'); }));
drop.addEventListener('drop', ev => { files.files = ev.dataTransfer.files; showFiles(); });

document.getElementById('f').addEventListener('submit', ev => {
  if (!files.files.length && !document.getElementById('useSamples').checked) {
    ev.preventDefault(); drop.classList.add('border-danger'); drop.scrollIntoView({behavior:'smooth', block:'center'}); return;
  }
  const ai = document.getElementById('aiSw').checked;
  const per = ai ? 15000 : 1500;
  document.getElementById('eta').textContent = ai ? (LOCAL ? 'LLM ในเครื่อง ประมาณ 1–3 นาที' : 'Claude อาจใช้เวลา 1–3 นาที') : 'โหมดกฎ ใช้เวลาไม่กี่วินาที';
  new bootstrap.Modal('#loading').show();
  const items = document.querySelectorAll('#loadSteps li'); let i = 0;
  const mark = () => {
    items.forEach((li, k) => {
      li.className = k < i ? 'done' : (k === i ? 'active' : '');
      li.querySelector('i').className = 'bi me-2 ' + (k < i ? 'bi-check-circle-fill' : (k === i ? 'bi-arrow-right-circle-fill text-primary' : 'bi-circle'));
    });
    document.getElementById('bar').style.width = Math.min(95, 10 + i * 25) + '%';
  };
  mark(); setInterval(() => { if (i < 3) { i++; mark(); } }, per);
});
</script>
JS;
layout_foot($js);
