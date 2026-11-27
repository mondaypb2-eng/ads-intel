<?php
require __DIR__ . '/config.php';
require APP_ROOT . '/lib/layout.php';
layout_head('Prompts', 'prompts.php');
$steps = [
    '1_normalize' => ['bi-funnel', 'Data Normalization Prompt', 'Claude ตอบเป็น JSON ตาม schema (structured outputs) เลือกได้เฉพาะชื่อคอลัมน์ที่มีจริง → PHP แปลงค่า/สกุลเงิน/คำนวณ metric เอง'],
    '2_diagnose' => ['bi-activity', 'Diagnostic & Anomaly Prompt', 'สถานะและ flag มาจากกฎที่ตรวจซ้ำได้ → Claude อธิบายสาเหตุจากตัวเลข + next steps'],
    '3_next_best_action' => ['bi-lightbulb', 'Next-Best-Action Prompt', 'ช่องว่างสินค้า × ช่องทาง + ผลงานปัจจุบัน → แคมเปญใหม่ กลุ่มเป้าหมาย พาดหัว'],
    '4_visual_report' => ['bi-image', 'Visual Report Generator Prompt', 'Claude เขียน HTML + Tailwind → ตรวจด้วย headless browser ว่าไม่ล้นกรอบ ถ้าไม่ผ่านใช้เทมเพลตแทน'],
];
?>
<h1 class="h3 fw-bold mb-1"><i class="bi bi-chat-square-text me-2"></i>Prompt Pipeline</h1>
<p class="text-body-secondary mb-4">แก้ไข prompt ได้ที่โฟลเดอร์ <code>prompts/</code> — ระบบโหลดใหม่ทุกครั้งที่รัน</p>
<div class="accordion" id="pAcc">
<?php $i = 0; foreach ($steps as $file => [$icon, $title, $desc]): $i++; ?>
  <div class="accordion-item">
    <h2 class="accordion-header"><button class="accordion-button <?= $i > 1 ? 'collapsed' : '' ?> gap-3" type="button" data-bs-toggle="collapse" data-bs-target="#p<?= $i ?>">
      <span class="step-num m-0"><?= $i ?></span>
      <span><span class="fw-semibold"><i class="bi <?= $icon ?> me-1"></i><?= h($title) ?></span><br><span class="small text-body-secondary"><?= h($desc) ?></span></span>
    </button></h2>
    <div id="p<?= $i ?>" class="accordion-collapse collapse <?= $i === 1 ? 'show' : '' ?>" data-bs-parent="#pAcc"><div class="accordion-body">
      <div class="d-flex justify-content-between align-items-center mb-2"><code class="small">prompts/<?= $file ?>.md</code>
        <button class="btn btn-sm btn-outline-secondary copy" data-t="pr<?= $i ?>"><i class="bi bi-clipboard me-1"></i>คัดลอก</button></div>
      <pre class="prompt" id="pr<?= $i ?>"><?= h(prompt_text($file)) ?></pre>
    </div></div>
  </div>
<?php endforeach; ?>
</div>
<?php layout_foot('<script>document.querySelectorAll(".copy").forEach(b=>b.onclick=async()=>{try{await navigator.clipboard.writeText(document.getElementById(b.dataset.t).textContent);b.innerHTML=\'<i class="bi bi-check2 me-1"></i>คัดลอกแล้ว\'}catch(e){}})</script>');
