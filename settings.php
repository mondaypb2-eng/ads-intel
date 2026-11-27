<?php
require __DIR__ . '/config.php';
require APP_ROOT . '/lib/layout.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new = ['ollama' => []];
    $new['provider'] = ($_POST['provider'] ?? '') === 'claude' ? 'claude' : 'ollama';
    if (preg_match('#^https?://[\w.\-:]+$#', $_POST['ollama_url'] ?? '')) $new['ollama']['url'] = rtrim($_POST['ollama_url'], '/');
    if (preg_match('/^[\w.\-:\/]+$/', $_POST['ollama_model'] ?? '')) $new['ollama']['model'] = $_POST['ollama_model'];
    if (in_array((int)($_POST['num_ctx'] ?? 0), [4096, 8192, 16384], true)) $new['ollama']['num_ctx'] = (int)$_POST['num_ctx'];
    $new['ollama']['think'] = !empty($_POST['think']);
    if (isset($_POST['clear_key'])) $new['api_key'] = '';
    elseif (trim($_POST['api_key'] ?? '') !== '') $new['api_key'] = trim($_POST['api_key']);
    $new['model'] = preg_replace('/[^a-z0-9.\-]/', '', $_POST['model'] ?? 'claude-opus-5-5') ?: 'claude-opus-5-5';
    $new['effort'] = in_array($_POST['effort'] ?? '', ['low', 'medium', 'high', 'xhigh', 'max'], true) ? $_POST['effort'] : 'medium';
    $new['base_currency'] = 'THB';
    foreach (['target_roas', 'breakeven_roas'] as $k) if (is_numeric($_POST[$k] ?? null) && $_POST[$k] > 0) $new[$k] = (float)$_POST[$k];
    $fx = ['THB' => 1];
    foreach ($_POST['fx'] ?? [] as $c => $rate) {
        if (isset($_POST['fx_del'][$c])) continue;
        if (preg_match('/^[A-Z]{3}$/', $c) && is_numeric($rate) && $rate > 0) $fx[$c] = (float)$rate;
    }
    $nc = strtoupper(trim($_POST['new_cur'] ?? ''));
    if (preg_match('/^[A-Z]{3}$/', $nc) && is_numeric($_POST['new_rate'] ?? null) && $_POST['new_rate'] > 0) $fx[$nc] = (float)$_POST['new_rate'];
    $new['fx'] = $fx;
    if (trim($_POST['edge_path'] ?? '') !== '') $new['edge_path'] = trim($_POST['edge_path']);
    save_settings($new);
    header('Location: settings.php?saved=1');
    exit;
}
$s = app_settings();
$test = null;
if (isset($_GET['test']) && Llm::available()) {
    try { $t0 = microtime(true); $reply = Llm::text('Reply with exactly: OK', 'ping', 50);
          $test = ['ok' => true, 'msg' => 'เชื่อมต่อ ' . Llm::label() . ' สำเร็จ (' . round(microtime(true) - $t0, 1) . ' วินาที) — ตอบกลับ: ' . $reply]; }
    catch (Throwable $e) { $test = ['ok' => false, 'msg' => $e->getMessage()]; }
}
$mask = $s['api_key'] ? substr($s['api_key'], 0, 10) . '…' . substr($s['api_key'], -4) : '';
$edge = Renderer::edge();
layout_head('ตั้งค่า', 'settings.php');
?>
<h1 class="h3 fw-bold mb-4"><i class="bi bi-gear me-2"></i>ตั้งค่า</h1>
<?php if (isset($_GET['saved'])): ?><div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-1"></i>บันทึกแล้ว<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if ($test): ?><div class="alert alert-<?= $test['ok'] ? 'success' : 'danger' ?>"><i class="bi bi-<?= $test['ok'] ? 'plug' : 'x-octagon' ?> me-1"></i><?= h($test['msg']) ?></div><?php endif; ?>

<?php $models = Llm::ollamaModels(); $o = $s['ollama']; $prob = Llm::problem(); ?>
<form method="post" class="row g-4">
  <div class="col-12"><div class="card">
    <div class="card-header d-flex justify-content-between"><span><i class="bi bi-cpu me-1 text-primary"></i>AI engine</span>
      <span class="badge <?= $prob ? 'text-bg-warning' : 'text-bg-success' ?>"><?= $prob ? h($prob) . ' → ใช้โหมดกฎ' : 'พร้อม · ' . h(Llm::label()) ?></span></div>
    <div class="card-body row g-3">
      <div class="col-md-6"><input type="radio" class="btn-check" name="provider" id="pv1" value="ollama" <?= $s['provider'] !== 'claude' ? 'checked' : '' ?>>
        <label class="btn btn-outline-primary w-100 text-start p-3 h-100" for="pv1"><div class="fw-bold"><i class="bi bi-pc-display me-1"></i>LLM ในเครื่อง (Ollama) — ฟรี</div>
          <div class="small">map คอลัมน์ · อธิบายสาเหตุ · เสนอแคมเปญ ด้วยโมเดลในเครื่อง ตัวเลขทั้งหมดยังคำนวณด้วยโค้ด · รายงานภาพใช้เทมเพลต</div></label></div>
      <div class="col-md-6"><input type="radio" class="btn-check" name="provider" id="pv2" value="claude" <?= $s['provider'] === 'claude' ? 'checked' : '' ?>>
        <label class="btn btn-outline-primary w-100 text-start p-3 h-100" for="pv2"><div class="fw-bold"><i class="bi bi-stars me-1"></i>Claude API — เสียค่าใช้จ่าย</div>
          <div class="small">คุณภาพภาษาดีกว่า และให้ Claude ออกแบบ HTML รายงานเองได้ · ต้องมี API key + เครดิต</div></label></div>
      <div class="col-md-5"><div class="form-floating"><input name="ollama_url" id="ou" value="<?= h($o['url']) ?>" class="form-control"><label for="ou">Ollama URL</label></div></div>
      <div class="col-md-4"><div class="form-floating"><select name="ollama_model" id="om" class="form-select">
        <?php foreach (array_unique(array_merge([$o['model']], array_filter($models ?? [], fn($m) => !str_contains($m, 'bge') && !str_contains($m, 'embed')))) as $m): ?><option <?= $m === $o['model'] ? 'selected' : '' ?>><?= h($m) ?></option><?php endforeach; ?>
      </select><label for="om">โมเดลในเครื่อง</label></div></div>
      <div class="col-md-3"><div class="form-floating"><select name="num_ctx" id="nc" class="form-select"><?php foreach ([4096, 8192, 16384] as $c): ?><option <?= (int)$o['num_ctx'] === $c ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select><label for="nc">Context</label></div></div>
      <div class="col-12 d-flex flex-wrap gap-3 align-items-center small">
        <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" name="think" id="th" <?= $o['think'] ? 'checked' : '' ?>><label class="form-check-label" for="th">โหมดคิดก่อนตอบ (ช้าลง 3–5 เท่า)</label></div>
        <span class="text-body-secondary"><?= $models === null ? '<span class="text-danger">ต่อ Ollama ไม่ได้</span>' : 'โมเดลในเครื่อง: ' . h(implode(', ', $models)) ?></span>
        <?php if (!$prob): ?><a href="settings.php?test=1" class="btn btn-sm btn-outline-primary ms-auto"><i class="bi bi-plug me-1"></i>ทดสอบ engine</a><?php endif; ?>
      </div>
    </div></div></div>
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-stars text-primary me-1"></i>Claude API</div>
      <div class="card-body vstack gap-3">
        <div>
          <label class="form-label">Anthropic API key <?= $mask ? '<span class="badge text-bg-success ms-1">ตั้งค่าแล้ว</span>' : '<span class="badge text-bg-warning ms-1">ยังไม่ตั้งค่า</span>' ?></label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-key"></i></span>
            <input name="api_key" id="key" type="password" placeholder="<?= $mask ? h($mask) . ' (เว้นว่าง = ใช้คีย์เดิม)' : 'sk-ant-…' ?>" class="form-control font-monospace" autocomplete="off">
            <button class="btn btn-outline-secondary" type="button" onclick="key.type=key.type==='password'?'text':'password'"><i class="bi bi-eye"></i></button>
          </div>
          <div class="form-text">ขอคีย์ได้ที่ <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a> · เก็บใน storage/settings.json (เว็บเข้าถึงไม่ได้)</div>
        </div>
        <div class="row g-3">
          <div class="col-sm-7"><div class="form-floating"><select name="model" id="model" class="form-select">
            <?php foreach (['claude-opus-5-5' => 'Claude Opus 5.5 (แนะนำ)', 'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (ถูกกว่า)', 'claude-haiku-5-5' => 'Claude Haiku 5.5 (ถูกสุด)', 'claude-fable-5-2' => 'Claude Fable 5.2 (เก่งสุด)'] as $k => $l): ?>
              <option value="<?= $k ?>" <?= $s['model'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select><label for="model">Model</label></div></div>
          <div class="col-sm-5"><div class="form-floating"><select name="effort" id="effort" class="form-select">
            <?php foreach (['low', 'medium', 'high', 'xhigh'] as $e): ?><option <?= $s['effort'] === $e ? 'selected' : '' ?>><?= $e ?></option><?php endforeach; ?></select><label for="effort">Effort</label></div></div>
        </div>
        <?php if ($s['api_key']): ?>
          <div class="d-flex gap-2">
            <a href="settings.php?test=1" class="btn btn-outline-primary"><i class="bi bi-plug me-1"></i>ทดสอบการเชื่อมต่อ</a>
            <button name="clear_key" value="1" class="btn btn-outline-danger" onclick="return confirm('ลบ API key?')"><i class="bi bi-trash me-1"></i>ลบคีย์</button>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-sliders me-1 text-primary"></i>เกณฑ์การวิเคราะห์</div>
      <div class="card-body vstack gap-3">
        <div class="row g-3">
          <div class="col-6"><div class="form-floating"><input name="target_roas" id="t1" type="number" step="0.1" value="<?= h($s['target_roas']) ?>" class="form-control"><label for="t1">ROAS เป้าหมาย (เขียว)</label></div></div>
          <div class="col-6"><div class="form-floating"><input name="breakeven_roas" id="t2" type="number" step="0.1" value="<?= h($s['breakeven_roas']) ?>" class="form-control"><label for="t2">ROAS จุดคุ้มทุน (แดง)</label></div></div>
        </div>
        <div>
          <label class="form-label">เบราว์เซอร์สำหรับแคป PNG</label>
          <input name="edge_path" value="<?= h($s['edge_path']) ?>" class="form-control font-monospace small">
          <div class="form-text <?= $edge ? 'text-success' : 'text-danger' ?>"><i class="bi bi-<?= $edge ? 'check-circle' : 'x-circle' ?> me-1"></i><?= $edge ? 'พบ: ' . h($edge) : 'ไม่พบเบราว์เซอร์ (Edge/Chrome)' ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between"><span><i class="bi bi-currency-exchange me-1 text-primary"></i>อัตราแลกเปลี่ยน → THB</span><span class="small fw-normal text-body-secondary">1 หน่วย = ? บาท</span></div>
      <div class="card-body">
        <div class="alert alert-warning small py-2"><i class="bi bi-info-circle me-1"></i>ค่าเริ่มต้นเป็นค่าสมมติ กรุณาแก้เป็นอัตราจริงก่อนใช้กับข้อมูลจริง</div>
        <div class="row g-3">
          <?php foreach ($s['fx'] as $c => $rate): if ($c === 'THB') continue; ?>
            <div class="col-6 col-md-3 col-xl-2">
              <div class="input-group"><span class="input-group-text fw-semibold"><?= h($c) ?></span>
                <input name="fx[<?= h($c) ?>]" type="number" step="0.0001" value="<?= h($rate) ?>" class="form-control">
                <input type="checkbox" class="btn-check" name="fx_del[<?= h($c) ?>]" id="del<?= h($c) ?>"><label class="btn btn-outline-danger" for="del<?= h($c) ?>" title="ลบ"><i class="bi bi-x"></i></label></div>
            </div>
          <?php endforeach; ?>
          <div class="col-6 col-md-3 col-xl-2"><div class="input-group"><input name="new_cur" maxlength="3" placeholder="เพิ่ม เช่น MYR" class="form-control text-uppercase"><input name="new_rate" type="number" step="0.0001" placeholder="อัตรา" class="form-control"></div></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-12"><button class="btn btn-primary btn-lg px-5"><i class="bi bi-save me-2"></i>บันทึก</button></div>
</form>
<?php layout_foot();
