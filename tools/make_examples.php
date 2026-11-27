<?php
// Builds examples/ : a self-contained showcase of one finished run, for presentations.
// Run: C:\xampp\php\php.exe tools\make_examples.php [run-id]   (default: newest run with a PNG)
// Output opens offline (double-click examples/index.html) or at http://localhost/ads-intel/examples/
$root = realpath(__DIR__ . '/..');
$runs = $root . '/runs';
$out = $root . '/examples';

$id = $argv[1] ?? null;
if (!$id) {
    $dirs = array_filter(glob("$runs/2*", GLOB_ONLYDIR), fn($d) => is_file("$d/report.png") && is_file("$d/result.json"));
    rsort($dirs);
    $id = basename($dirs[0] ?? '');
}
$src = "$runs/$id";
if (!$id || !is_file("$src/result.json")) { fwrite(STDERR, "Run not found: $id\n"); exit(1); }
$r = json_decode(file_get_contents("$src/result.json"), true);

@mkdir("$out/input", 0777, true);
@mkdir("$out/output", 0777, true);
foreach (['report.png', 'report.html', 'result.json', 'normalized.json'] as $f) copy("$src/$f", "$out/output/$f");
foreach (['meta_ads_export.csv', 'google_ads_report.csv', 'tiktok_ads.json', 'products.json'] as $f) copy("$root/samples/$f", "$out/input/$f");

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$cur = $r['settings']['base_currency'];
$sym = $cur === 'THB' ? '฿' : '';
$money = fn($v) => $v === null ? '—' : $sym . number_format($v, 0);
$short = function ($v) use ($sym) {
    if ($v >= 1e6) return $sym . number_format($v / 1e6, 2) . 'M';
    if ($v >= 1e3) return $sym . number_format($v / 1e3, 1) . 'K';
    return $sym . number_format($v, 0);
};
$t = $r['totals'];
$diag = $r['diagnostics']['campaigns'];
$cnt = ['green' => 0, 'yellow' => 0, 'red' => 0];
foreach ($diag as $d) $cnt[$d['status']]++;
$fmtDate = fn($d) => date('j M Y', strtotime($d));

// Raw input previews: first lines exactly as the platforms export them
$rawLines = function ($file, $n) {
    $lines = preg_split('/\r?\n/', preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($file)));
    return implode("\n", array_slice($lines, 0, $n));
};
$tiktok = json_decode(file_get_contents("$root/samples/tiktok_ads.json"), true);
$tiktokPreview = json_encode(['code' => 0, 'data' => ['list' => [$tiktok['data']['list'][0]]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$inputs = [
    ['Meta', 'meta_ads_export.csv', 'CSV ภาษาไทย/อังกฤษ มี BOM · สกุลเงิน THB · ช่องว่างเมื่อไม่มียอดขาย', $rawLines("$root/samples/meta_ads_export.csv", 4)],
    ['Google', 'google_ads_report.csv', 'มีแถวหัวรายงานก่อนหัวตาราง · USD · ตัวเลขมีคอมมา · แถว Total ปิดท้าย', $rawLines("$root/samples/google_ads_report.csv", 5)],
    ['TikTok', 'tiktok_ads.json', 'JSON จาก API · ตัวเลขเป็นสตริง · ชื่อฟิลด์ไม่เหมือนแพลตฟอร์มอื่น', $tiktokPreview],
];

$statusTh = ['green' => 'ขยายงบ', 'yellow' => 'ปรับปรุง', 'red' => 'หยุด/ลดงบ'];
$flagTh = ['cpa_outlier' => 'CPA สูงผิดปกติ', 'cpa_spike' => 'CPA พุ่งช่วงหลัง', 'creative_fatigue' => 'ครีเอทีฟล้า', 'no_conversion' => 'ไม่มี conversion'];
$platColor = ['Meta' => '#2f6bff', 'Google' => '#16a34a', 'TikTok' => '#111827'];
$maxRev = max(array_column($r['platforms'], 'revenue'));

$rows = '';
foreach ($r['campaigns'] as $c) {
    $d = $diag[$c['id']];
    $flags = '';
    foreach ($d['flags'] as $f) $flags .= '<span class="flag ' . $h($f['level']) . '" title="' . $h($f['text']) . '">' . $h($flagTh[$f['code']] ?? $f['code']) . '</span>';
    $rows .= '<tr><td><span class="dot ' . $d['status'] . '"></span></td><td><b>' . $h($c['campaign_name']) . '</b><div class="mute sm">' . $h($c['platform']) . '</div>'
        . ($d['flags'] ? '<div class="flags">' . $flags . '</div><div class="why sm">' . $h($d['flags'][0]['text']) . '</div>' : '')
        . '</td><td class="num">' . $money($c['spend']) . '</td><td class="num">' . $money($c['revenue']) . '</td><td class="num"><b>' . number_format($c['roas'], 2) . 'x</b></td><td class="num">' . $money($c['cpa'])
        . '</td><td><span class="pill ' . $d['status'] . '">' . $statusTh[$d['status']] . '</span></td></tr>';
}

$plats = '';
foreach ($r['platforms'] as $p) {
    $plats .= '<div class="plat"><div class="pname"><span class="pdot" style="background:' . ($platColor[$p['platform']] ?? '#888') . '"></span>' . $h($p['platform']) . '</div><div class="bars">'
        . '<div class="bar spend" style="width:' . round($p['spend'] / $maxRev * 100, 1) . '%"><span>งบ ' . $short($p['spend']) . '</span></div>'
        . '<div class="bar rev" style="width:' . round($p['revenue'] / $maxRev * 100, 1) . '%"><span>รายได้ ' . $short($p['revenue']) . '</span></div></div>'
        . '<div class="proas ' . ($p['roas'] >= $r['settings']['target_roas'] ? 'good' : '') . '">' . number_format($p['roas'], 2) . 'x<small>ROAS</small></div></div>';
}

// Highlight cards: the three signals planted in the demo data
$pick = function ($code) use ($r, $diag) {
    foreach ($r['campaigns'] as $c) foreach ($diag[$c['id']]['flags'] as $f) if ($f['code'] === $code) return [$c, $diag[$c['id']], $f];
    return null;
};
$highlights = '';
foreach (['creative_fatigue' => 'bi-repeat', 'cpa_spike' => 'bi-graph-up-arrow', 'no_conversion' => 'bi-slash-circle'] as $code => $_) {
    $x = $code === 'cpa_spike' ? null : $pick($code);
    if ($code === 'cpa_spike') { // prefer the critical spike (Google Generic) over the Meta one already shown under fatigue
        foreach ($r['campaigns'] as $c) foreach ($diag[$c['id']]['flags'] as $f) if ($f['code'] === 'cpa_spike' && $f['level'] === 'critical') $x = [$c, $diag[$c['id']], $f];
        $x ??= $pick($code);
    }
    if (!$x) continue;
    [$c, $d, $f] = $x;
    $steps = '';
    foreach (array_slice($d['next_steps'], 0, 2) as $s) $steps .= '<li>' . $h($s) . '</li>';
    $highlights .= '<div class="card hl ' . $d['status'] . '"><div class="hl-tag">' . $h($flagTh[$code]) . '</div><h3>' . $h($c['campaign_name']) . '</h3><div class="mute sm">' . $h($c['platform']) . '</div>'
        . '<p class="evidence">' . $h($f['text']) . '</p>' . (!empty($d['cause']) ? '<p class="sm"><b>สาเหตุที่น่าจะเป็น:</b> ' . $h($d['cause']) . '</p>' : '')
        . '<ul class="sm">' . $steps . '</ul></div>';
}

$nba = $r['next_best_action'];
$gaps = '';
foreach ($nba['gaps'] as $g) {
    $gaps .= '<tr><td><b>' . $h($g['product']) . '</b></td><td>' . ($g['running'] ? $h(implode(', ', $g['running'])) : '<span class="mute">ยังไม่ได้ยิง</span>') . '</td><td>'
        . implode(' ', array_map(fn($m) => '<span class="chip">' . $h($m) . '</span>', $g['missing'])) . '</td></tr>';
}
$props = '';
foreach ($nba['proposals'] as $p) {
    $a = $p['audience'];
    $hl = '';
    foreach ($p['headlines'] as $x) $hl .= '<li>“' . $h($x) . '”</li>';
    $props .= '<div class="card prop"><div class="prop-top"><span class="chip solid" style="background:' . ($platColor[$p['platform']] ?? '#5b5bd6') . '">' . $h($p['platform']) . '</span>'
        . '<span class="prio ' . $h($p['priority']) . '">' . ($p['priority'] === 'high' ? 'สำคัญสูง' : ($p['priority'] === 'medium' ? 'สำคัญกลาง' : 'ทดลอง')) . '</span></div>'
        . '<h3>' . $h($p['product']) . '</h3><div class="code sm">' . $h($p['campaign_name']) . '</div>'
        . '<dl><dt>เป้าหมาย</dt><dd>' . $h($p['objective']) . '</dd><dt>งบต่อวัน</dt><dd>' . $money($p['daily_budget']) . '</dd>'
        . '<dt>กลุ่มเป้าหมาย</dt><dd>' . $h(($a['gender'] ?? '') . ' อายุ ' . ($a['age'] ?? '') . ' · ' . implode(', ', $a['interests'] ?? [])) . '</dd></dl>'
        . '<div class="sm mute">พาดหัวที่เสนอ</div><ul class="sm hls">' . $hl . '</ul><p class="sm why">' . $h($p['rationale']) . '</p></div>';
}

$map = $r['mappings'][1]['map'];
$mapRows = '';
foreach (['date', 'campaign_name', 'spend', 'impressions', 'clicks', 'conversions', 'revenue'] as $k) $mapRows .= '<tr><td><code>' . $h($map[$k] ?? '') . '</code></td><td>→</td><td><code>' . $k . '</code></td></tr>';
$fx = $r['settings']['fx'];
$tm = $r['timing'];
$model = $r['ai_used'] ? $r['model'] : 'โหมดกฎ (ไม่ใช้ AI)';

$css = <<<'CSS'
:root{--ink:#0f172a;--mute:#64748b;--line:#e2e8f0;--bg:#f8fafc;--card:#fff;--accent:#5b5bd6;--green:#059669;--yellow:#d97706;--red:#e11d48}
*{box-sizing:border-box}html{scroll-behavior:smooth}
body{margin:0;font-family:"Noto Sans Thai","Leelawadee UI","Segoe UI",Tahoma,sans-serif;color:var(--ink);background:var(--bg);line-height:1.6;font-size:16px}
.wrap{max-width:1120px;margin:0 auto;padding:0 24px}
header.hero{background:#0f172a;color:#fff;padding:56px 0 64px}
.eyebrow{letter-spacing:.2em;font-weight:700;color:#a5b4fc;font-size:13px;text-transform:uppercase}
h1{font-size:40px;line-height:1.2;margin:10px 0 8px}h2{font-size:28px;margin:0 0 6px}h3{font-size:18px;margin:4px 0}
.lead{color:#cbd5e1;font-size:18px;max-width:760px;margin:0}
.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-top:36px}
.kpi{background:#1e293b;border-radius:16px;padding:18px 20px}.kpi .l{color:#94a3b8;font-size:14px}.kpi .v{font-size:32px;font-weight:700}.kpi .s{color:#94a3b8;font-size:13px}
.meta{display:flex;flex-wrap:wrap;gap:8px;margin-top:20px}.meta span{background:#1e293b;border:1px solid #334155;border-radius:99px;padding:3px 12px;font-size:13px;color:#cbd5e1}
nav.toc{position:sticky;top:0;z-index:5;background:rgba(255,255,255,.92);backdrop-filter:blur(8px);border-bottom:1px solid var(--line)}
nav.toc .wrap{display:flex;gap:4px;overflow-x:auto;padding:8px 24px}nav.toc a{white-space:nowrap;color:var(--mute);text-decoration:none;padding:6px 12px;border-radius:8px;font-size:14px}nav.toc a:hover{background:#eef2ff;color:var(--accent)}
section{padding:56px 0;border-bottom:1px solid var(--line)}
.step-no{display:inline-block;background:#eef2ff;color:var(--accent);font-weight:700;border-radius:8px;padding:2px 10px;font-size:13px;margin-bottom:8px}
.mute{color:var(--mute)}.sm{font-size:14px}
.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:24px}.grid2{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:24px}
.grid3>*,.grid2>*,.pipe>*,.report>*{min-width:0}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:20px}
pre{background:#0f172a;color:#e2e8f0;border-radius:10px;padding:12px;font-size:12px;overflow:auto;max-height:240px;margin:12px 0 0;white-space:pre}
code,.code{font-family:Consolas,"Cascadia Mono",monospace;font-size:13px}.code{color:var(--accent)}
table{width:100%;border-collapse:collapse;font-size:15px}th{text-align:left;color:var(--mute);font-weight:500;font-size:13px;border-bottom:1px solid var(--line);padding:8px}td{padding:10px 8px;border-bottom:1px solid #f1f5f9;vertical-align:top}.num{text-align:right;white-space:nowrap}th.num{text-align:right}
.dot{display:inline-block;width:12px;height:12px;border-radius:50%;margin-top:6px}.dot.green{background:var(--green)}.dot.yellow{background:#fbbf24}.dot.red{background:var(--red)}
.pill{display:inline-block;border-radius:99px;padding:2px 12px;font-size:13px;font-weight:600;white-space:nowrap}.pill.green{background:#ecfdf5;color:var(--green)}.pill.yellow{background:#fffbeb;color:var(--yellow)}.pill.red{background:#fff1f2;color:var(--red)}
.flags{margin-top:4px}.flag{display:inline-block;font-size:12px;border-radius:6px;padding:0 8px;margin:2px 4px 0 0}.flag.critical{background:#ffe4e6;color:#be123c}.flag.warning{background:#fef3c7;color:#92400e}
.why{color:var(--mute)}
.counts{display:flex;gap:12px;margin:20px 0}.counts div{flex:1;border-radius:14px;padding:14px 18px;font-weight:600}.counts b{font-size:28px;display:block}
.c-green{background:#ecfdf5;color:var(--green)}.c-yellow{background:#fffbeb;color:var(--yellow)}.c-red{background:#fff1f2;color:var(--red)}
.pipe{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:24px}.pipe .card{position:relative}.pipe .card:not(:last-child)::after{content:"→";position:absolute;right:-14px;top:40%;color:var(--accent);font-weight:700}
.pipe .n{font-size:13px;color:var(--accent);font-weight:700}.pipe ul{padding-left:18px;margin:8px 0 0;font-size:14px}
.tag-ai{display:inline-block;font-size:11px;background:#eef2ff;color:var(--accent);border-radius:6px;padding:0 6px;margin-left:4px}.tag-code{display:inline-block;font-size:11px;background:#f1f5f9;color:#334155;border-radius:6px;padding:0 6px;margin-left:4px}
.plat{display:grid;grid-template-columns:110px 1fr 90px;gap:12px;align-items:center;padding:10px 0}.pname{font-weight:700;font-size:18px}.pdot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:8px}
.bar{height:16px;border-radius:6px;margin:4px 0;position:relative;min-width:4px}.bar span{position:absolute;left:calc(100% + 8px);top:-3px;font-size:13px;color:#475569;white-space:nowrap}.bar.spend{background:#cbd5e1}.bar.rev{background:var(--accent)}.bars{padding-right:110px}
.proas{text-align:right;font-size:24px;font-weight:700}.proas.good{color:var(--green)}.proas small{display:block;font-size:11px;color:var(--mute);font-weight:400}
.hl{border-top:4px solid}.hl.yellow{border-top-color:#fbbf24}.hl.red{border-top-color:var(--red)}.hl-tag{font-size:12px;font-weight:700;color:var(--mute);text-transform:uppercase;letter-spacing:.06em}
.evidence{background:#f8fafc;border-radius:10px;padding:10px 12px;font-weight:600;margin:12px 0}.hl ul{padding-left:18px;margin:6px 0 0}
.chip{display:inline-block;border:1px solid var(--line);border-radius:99px;padding:0 10px;font-size:13px;margin:0 4px 4px 0}.chip.solid{color:#fff;border:0;font-weight:600}
.prop-top{display:flex;justify-content:space-between;align-items:center}.prio{font-size:12px;font-weight:700}.prio.high{color:var(--red)}.prio.medium{color:var(--yellow)}.prio.low{color:var(--mute)}
dl{display:grid;grid-template-columns:96px 1fr;gap:4px 8px;font-size:14px;margin:12px 0}dt{color:var(--mute)}dd{margin:0}.hls{padding-left:18px;margin:4px 0 8px}
.report{display:grid;grid-template-columns:420px 1fr;gap:32px;align-items:start;margin-top:24px}.report img{width:100%;border-radius:12px;box-shadow:0 20px 50px rgba(15,23,42,.18);border:1px solid var(--line)}
.files a{display:flex;justify-content:space-between;gap:12px;padding:12px 14px;border:1px solid var(--line);border-radius:12px;text-decoration:none;color:var(--ink);background:#fff;margin-bottom:8px}.files a:hover{border-color:var(--accent)}.files small{color:var(--mute)}
.btn{display:inline-block;background:var(--accent);color:#fff;text-decoration:none;border-radius:10px;padding:10px 18px;font-weight:600;margin:4px 8px 4px 0}.btn.ghost{background:#fff;color:var(--accent);border:1px solid #c7d2fe}
footer{padding:32px 0;color:var(--mute);font-size:14px}
@media (max-width:900px){.kpis,.pipe{grid-template-columns:1fr 1fr}.grid3,.grid2,.report{grid-template-columns:1fr}.pipe .card::after{display:none}h1{font-size:30px}}
@media print{nav.toc{display:none}section{break-inside:avoid-page}header.hero{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
CSS;

$period = $fmtDate($r['period'][0]) . ' – ' . $fmtDate($r['period'][1]);
$inputCards = '';
foreach ($inputs as [$p, $f, $note, $raw]) {
    $inputCards .= '<div class="card"><div class="prop-top"><h3><span class="pdot" style="background:' . $platColor[$p] . '"></span>' . $p . '</h3><a class="sm" href="input/' . $f . '" download>ดาวน์โหลด</a></div>'
        . '<div class="code sm">' . $f . '</div><p class="sm mute" style="margin:6px 0 0">' . $h($note) . '</p><pre>' . $h($raw) . '</pre></div>';
}
$prodList = '';
foreach ($r['products'] as $p) $prodList .= '<tr><td><b>' . $h($p['name']) . '</b></td><td class="num">' . $money($p['price']) . '</td><td>' . $h($p['audience']) . '</td><td>' . ($p['channels'] ? $h(implode(', ', $p['channels'])) : '<span class="mute">—</span>') . '</td></tr>';

$html = <<<HTML
<!doctype html>
<html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ตัวอย่างผลลัพธ์ · Multi-Channel Ads Intelligence</title><style>$css</style></head><body>
<header class="hero"><div class="wrap">
  <div class="eyebrow">ตัวอย่างสำหรับนำเสนอ · Ads Intelligence Engine</div>
  <h1>จากไฟล์โฆษณา 3 แพลตฟอร์ม สู่รายงานพร้อมคำแนะนำในคลิกเดียว</h1>
  <p class="lead">ตัวอย่างนี้ใช้ข้อมูลจำลองของแบรนด์สกินแคร์ 10 แคมเปญบน Meta, Google และ TikTok ช่วง $period ระบบรวมข้อมูล ตรวจหาความผิดปกติ แนะนำว่าควรขยาย ปรับ หรือหยุดแคมเปญไหน และเสนอแคมเปญใหม่ที่ควรยิง</p>
  <div class="kpis">
    <div class="kpi"><div class="l">งบที่ใช้</div><div class="v">{$short($t['spend'])}</div><div class="s">{$h(number_format($t['impressions']))} impressions</div></div>
    <div class="kpi"><div class="l">รายได้</div><div class="v">{$short($t['revenue'])}</div><div class="s">{$h(number_format($t['conversions']))} conversions</div></div>
    <div class="kpi"><div class="l">ROAS</div><div class="v">{$h(number_format($t['roas'], 2))}x</div><div class="s">เป้า {$h(number_format($r['settings']['target_roas'], 1))}x · คุ้มทุน {$h($r['settings']['breakeven_roas'])}x</div></div>
    <div class="kpi"><div class="l">CPA</div><div class="v">{$money($t['cpa'])}</div><div class="s">CTR {$h(number_format($t['ctr'], 2))}%</div></div>
  </div>
  <div class="meta"><span>{$h(count($r['files']))} ไฟล์ · {$h($r['row_count'])} แถว</span><span>แถวเสีย {$h(count($r['invalid_rows']))}</span><span>AI: {$h($model)}</span><span>ใช้เวลา {$h(round($tm['total']))} วินาที</span><span>สกุลเงินหลัก $cur</span></div>
</div></header>
<nav class="toc"><div class="wrap"><a href="#input">1 ข้อมูลเข้า</a><a href="#pipeline">2 ขั้นตอน</a><a href="#platforms">3 ภาพรวม</a><a href="#diagnose">4 วินิจฉัย</a><a href="#nba">5 แคมเปญใหม่</a><a href="#report">6 รายงาน</a><a href="#files">ไฟล์ทั้งหมด</a></div></nav>

<section id="input"><div class="wrap">
  <span class="step-no">1 · ข้อมูลเข้า</span><h2>ไฟล์ดิบจากแต่ละแพลตฟอร์ม รูปแบบไม่เหมือนกันเลย</h2>
  <p class="mute">ทีมไม่ต้องจัดรูปแบบก่อน อัปโหลดไฟล์ที่ export มาได้ทันที ระบบจับคู่คอลัมน์และแปลงสกุลเงินให้เอง</p>
  <div class="grid3">$inputCards</div>
  <div class="card" style="margin-top:16px"><h3>รายการสินค้า (ใช้หาโอกาสยิงแคมเปญใหม่)</h3>
  <div style="overflow-x:auto"><table><thead><tr><th>สินค้า</th><th class="num">ราคา</th><th>กลุ่มเป้าหมาย</th><th>ช่องทางที่ยิงอยู่</th></tr></thead><tbody>$prodList</tbody></table></div></div>
</div></section>

<section id="pipeline"><div class="wrap">
  <span class="step-no">2 · ขั้นตอนการทำงาน</span><h2>4 ขั้นตอน ตัวเลขทุกตัวคำนวณด้วยโค้ด AI ไม่ได้คิดเลขเอง</h2>
  <p class="mute">AI ช่วยอธิบายสาเหตุและเขียนข้อเสนอ ส่วน ROAS, CPA และยอดรวมคำนวณใน PHP จากยอดรวมจริงเสมอ จึงตรวจสอบย้อนกลับได้</p>
  <div class="pipe">
    <div class="card"><div class="n">ขั้น 1</div><h3>รวมและทำความสะอาด<span class="tag-code">โค้ด</span></h3><ul><li>จับคู่คอลัมน์จากพจนานุกรม ({$h(count($r['mappings']))}/{$h(count($r['mappings']))} ไฟล์)</li><li>แปลง USD → THB ที่ {$h($fx['USD'])}</li><li>คำนวณ CTR, CPC, CPA, ROAS</li><li>{$h($r['row_count'])} แถวผ่าน schema</li></ul></div>
    <div class="card"><div class="n">ขั้น 2</div><h3>วินิจฉัยความผิดปกติ<span class="tag-code">โค้ด</span><span class="tag-ai">AI</span></h3><ul><li>CPA สูงกว่ามัธยฐาน</li><li>CPA พุ่งช่วงหลัง</li><li>ครีเอทีฟล้า (CTR ลด, ความถี่เพิ่ม)</li><li>ใช้งบแต่ไม่มี conversion</li></ul></div>
    <div class="card"><div class="n">ขั้น 3</div><h3>Next-Best-Action<span class="tag-code">โค้ด</span><span class="tag-ai">AI</span></h3><ul><li>หาช่องว่าง สินค้า × ช่องทาง</li><li>เสนอแคมเปญใหม่ งบ กลุ่มเป้าหมาย และพาดหัว</li></ul></div>
    <div class="card"><div class="n">ขั้น 4</div><h3>รายงานภาพ<span class="tag-code">โค้ด</span></h3><ul><li>เทมเพลต 1080×1350 สำหรับโพสต์/ส่งแชต</li><li>ตรวจขนาดด้วย headless Edge แล้วแคปเป็น PNG</li></ul></div>
  </div>
  <div class="grid2"><div class="card"><h3>ตัวอย่างการจับคู่คอลัมน์ (Google)</h3><table>$mapRows</table></div>
  <div class="card"><h3>เวลาที่ใช้ต่อขั้น (วินาที)</h3><table>
    <tr><td>รวมและทำความสะอาด</td><td class="num">{$h($tm['normalize'])}</td></tr><tr><td>วินิจฉัย</td><td class="num">{$h($tm['diagnose'])}</td></tr>
    <tr><td>Next-Best-Action</td><td class="num">{$h($tm['next_best_action'])}</td></tr><tr><td>รายงาน</td><td class="num">{$h($tm['report'])}</td></tr>
    <tr><td><b>รวม</b></td><td class="num"><b>{$h($tm['total'])}</b></td></tr></table>
    <p class="sm mute">รันด้วย {$h($model)} บนเครื่อง เวลาส่วนใหญ่ใช้ไปกับ AI ถ้าใช้โหมดกฎอย่างเดียวจะเสร็จในไม่กี่วินาที</p></div></div>
</div></section>

<section id="platforms"><div class="wrap">
  <span class="step-no">3 · ภาพรวม</span><h2>เปรียบเทียบแพลตฟอร์ม</h2>
  <p class="mute">ทุกแพลตฟอร์มแปลงเป็น $cur แล้ว จึงเทียบกันได้ตรง ๆ</p>
  <div class="card" style="margin-top:20px">$plats</div>
</div></section>

<section id="diagnose"><div class="wrap">
  <span class="step-no">4 · วินิจฉัย</span><h2>แคมเปญไหนควรขยาย ปรับ หรือหยุด</h2>
  <div class="counts"><div class="c-green"><b>{$cnt['green']}</b>ขยายงบ</div><div class="c-yellow"><b>{$cnt['yellow']}</b>ปรับปรุง</div><div class="c-red"><b>{$cnt['red']}</b>หยุด/ลดงบ</div></div>
  <h3 style="margin-top:28px">สัญญาณที่ระบบจับได้</h3><div class="grid3">$highlights</div>
  <div class="card" style="margin-top:24px;overflow-x:auto"><table><thead><tr><th></th><th>แคมเปญ</th><th class="num">งบ</th><th class="num">รายได้</th><th class="num">ROAS</th><th class="num">CPA</th><th>คำแนะนำ</th></tr></thead><tbody>$rows</tbody></table></div>
</div></section>

<section id="nba"><div class="wrap">
  <span class="step-no">5 · Next-Best-Action</span><h2>แคมเปญใหม่ที่ควรยิง</h2>
  <p class="mute">ระบบดูว่าสินค้าไหนยังไม่ได้ยิงในช่องทางไหน แล้วเสนอแคมเปญโดยอิงผลงานของช่องทางนั้นในบัญชี</p>
  <div class="card" style="margin-top:20px"><h3>ช่องว่าง สินค้า × ช่องทาง</h3><table><thead><tr><th>สินค้า</th><th>ยิงอยู่</th><th>ยังไม่ได้ยิง</th></tr></thead><tbody>$gaps</tbody></table></div>
  <div class="grid3">$props</div>
</div></section>

<section id="report"><div class="wrap">
  <span class="step-no">6 · ผลลัพธ์สุดท้าย</span><h2>รายงานภาพ 1080×1350 พร้อมส่ง</h2>
  <div class="report"><a href="output/report.png" target="_blank"><img src="output/report.png" alt="รายงานสรุปผลโฆษณาข้ามแพลตฟอร์ม"></a>
  <div><p>สรุปทั้งบัญชีในภาพเดียว ใช้โพสต์ใน LINE, Slack หรือแนบในอีเมลถึงลูกค้าได้ทันที มีทั้ง KPI หลัก การเทียบแพลตฟอร์ม สถานะของแต่ละแคมเปญพร้อมเหตุผล และแคมเปญใหม่ที่แนะนำ</p>
  <p class="sm mute">ระบบตรวจว่าเนื้อหาไม่ล้นกรอบ ({$h($r['report']['fit']['w'])}×{$h($r['report']['fit']['h'])} · ล้นกรอบ {$h(count($r['report']['fit']['clipped']))} จุด) ก่อนแคปเป็น PNG</p>
  <a class="btn" href="output/report.png" download="ads-report-example.png">ดาวน์โหลด PNG</a><a class="btn ghost" href="output/report.html" target="_blank">เปิดเป็น HTML</a></div></div>
</div></section>

<section id="files"><div class="wrap">
  <h2>ไฟล์ทั้งหมดในชุดตัวอย่าง</h2>
  <div class="grid2"><div class="files"><h3>ข้อมูลเข้า (input/)</h3>
    <a href="input/meta_ads_export.csv" download><span>meta_ads_export.csv</span><small>Meta · CSV · THB</small></a>
    <a href="input/google_ads_report.csv" download><span>google_ads_report.csv</span><small>Google · CSV · USD</small></a>
    <a href="input/tiktok_ads.json" download><span>tiktok_ads.json</span><small>TikTok · JSON · THB</small></a>
    <a href="input/products.json" download><span>products.json</span><small>รายการสินค้า</small></a></div>
  <div class="files"><h3>ผลลัพธ์ (output/)</h3>
    <a href="output/report.png" download><span>report.png</span><small>รายงานภาพ 1080×1350</small></a>
    <a href="output/report.html" target="_blank"><span>report.html</span><small>รายงานแบบ HTML</small></a>
    <a href="output/normalized.json" target="_blank"><span>normalized.json</span><small>ข้อมูลรวมหลังทำความสะอาด {$h($r['row_count'])} แถว</small></a>
    <a href="output/result.json" target="_blank"><span>result.json</span><small>ผลวินิจฉัยและข้อเสนอทั้งหมด</small></a></div></div>
</div></section>
<footer><div class="wrap">ข้อมูลจำลองเพื่อสาธิตเท่านั้น ไม่ใช่ข้อมูลของแบรนด์จริง · สร้างจากรัน <code>{$h($r['id'])}</code> · สร้างใหม่ได้ด้วย <code>php tools/make_examples.php</code></div></footer>
</body></html>
HTML;

file_put_contents("$out/index.html", $html);
echo "examples/ built from run $id\n";
