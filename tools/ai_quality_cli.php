<?php
/**
 * AI-quality eval: checks that what the local LLM WRITES is true to the data (the main eval only checks numbers/schema/layout).
 * Usage: php tools/ai_quality_cli.php [--runs=N] [--set=samples|alt|all] [-v]
 * Writes runs/ai-quality-latest.json
 */
require __DIR__ . '/../config.php';
set_time_limit(0);

$opts = getopt('v', ['runs::', 'set::']);
$runs = max(1, (int)($opts['runs'] ?? 1));
$setArg = $opts['set'] ?? 'all';
$verbose = isset($opts['v']);
if (!Llm::available()) { fwrite(STDERR, 'LLM not available: ' . Llm::problem() . "\n"); exit(1); }

$files = [];
foreach (['meta_ads_export.csv', 'google_ads_report.csv', 'tiktok_ads.json'] as $f) $files[] = ['name' => $f, 'content' => file_get_contents(APP_ROOT . "/samples/$f")];
$sets = [
    'samples' => file_get_contents(APP_ROOT . '/samples/products.json'),
    'alt' => file_get_contents(APP_ROOT . '/eval/fixtures/products_alt.json'),
];
if ($setArg !== 'all') $sets = [$setArg => $sets[$setArg]];

$settings = app_settings();
$settings['fx'] = Evaluator::FX; $settings['base_currency'] = 'THB'; $settings['target_roas'] = 3.0; $settings['breakeven_roas'] = 1.5;

const PLATFORM_WORDS = [
    'Meta' => '/\b(Meta|Facebook|Instagram|IG|Reels|Advantage\+)\b|เฟซบุ๊ก|เฟสบุ๊ค|อินสตาแกรม/iu',
    'Google' => '/\b(Google|YouTube|PMax|Performance Max|Search Ads?)\b|กูเกิล|ยูทูบ/iu',
    'TikTok' => '/\b(TikTok|Spark Ads?|TopView)\b|ติ๊กต็อก/iu',
];
const CLAIM_RE = '/หายขาด|หายภายใน|ภายใน\s*\d+|\d+\s*(วัน|สัปดาห์|เดือน)|รับประกัน|การันตี|100\s*%|ถาวร|เห็นผลทันที|ปลอดภัย\s*100|อันดับ\s*1|ดีที่สุด/u';

/** Dice similarity on character bigrams (works for Thai, which has no spaces between words). */
function sim(string $a, string $b): float
{
    $bg = function ($s) { $s = preg_replace('/[\s\p{P}]+/u', '', mb_strtolower($s)); $o = []; for ($i = 0; $i < mb_strlen($s) - 1; $i++) $o[] = mb_substr($s, $i, 2); return $o; };
    $x = $bg($a); $y = $bg($b);
    if (!$x || !$y) return 0;
    $cy = array_count_values($y); $inter = 0;
    foreach (array_count_values($x) as $g => $n) $inter += min($n, $cy[$g] ?? 0);
    return 2 * $inter / (count($x) + count($y));
}

$all = [];
for ($i = 1; $i <= $runs; $i++) foreach ($sets as $set => $productsText) {
    $t0 = microtime(true);
    $res = Pipeline::run($files, $productsText, ['ai' => true, 'report_mode' => 'template', 'settings' => $settings, 'id' => "aiq-$set", 'png' => false]);
    $checks = [];
    $add = function ($group, $name, $ok, $detail = '') use (&$checks) { $checks[] = compact('group', 'name', 'ok', 'detail'); };
    $camps = [];
    foreach ($res['campaigns'] as $c) $camps[$c['id']] = $c;

    // ================= Diagnostics =================
    $diag = $res['diagnostics']['campaigns'];
    $targets = array_values(array_filter($res['campaigns'], fn($c) => $diag[$c['id']]['status'] !== 'green'));
    usort($targets, fn($a, $b) => $b['spend'] <=> $a['spend']);
    $targets = array_slice($targets, 0, 6);
    $stepSets = [];
    foreach ($targets as $c) {
        $d = $diag[$c['id']];
        $label = "{$c['platform']}/{$c['campaign_name']}";
        $cause = $d['cause'] ?? (preg_match('/สาเหตุที่น่าจะเป็น:\s*(.+)$/u', $d['explanation'], $m) ? $m[1] : '');
        $add('diagnose', "$label: มีสาเหตุจาก AI", !empty($d['ai']) && trim($cause) !== '', $cause);
        $add('diagnose', "$label: สาเหตุไม่ใช่การทวนสถานะ", $cause !== '' && !preg_match('/อยู่ระหว่างจุดคุ้มทุน|ต้องการการปรับปรุงเพื่อเพิ่มประสิทธิภาพ|ต่ำกว่าเป้าหมาย$/u', $cause), $cause);
        $codes = array_column($d['flags'], 'code');
        if (array_intersect($codes, ['creative_fatigue', 'high_frequency'])) $re = '/ครีเอทีฟ|ความถี่|ซ้ำ|เบื่อ|ล้า|creative/iu';
        elseif (in_array('no_conversion', $codes, true)) $re = '/กลุ่มเป้าหมาย|ติดตาม|tracking|pixel|พิกเซล|แปลง|คอนเวอร์ชัน|วัตถุประสงค์|การรับรู้|awareness|ซื้อ/iu';
        elseif (array_intersect($codes, ['cpa_spike', 'cpa_outlier'])) $re = '/CPA|CPC|ต้นทุน|ค่าคลิก|คลิก|landing|แลนดิ้ง|หน้าเว็บ|หน้าเพจ|หน้าสินค้า|ข้อเสนอ|ราคา|คู่แข่ง|แข่งขัน|บิด|ประมูล|แปลง|คีย์เวิร์ด|keyword/iu';
        else $re = '/มูลค่า|ยอดขาย|ตะกร้า|ราคา|อัตรา|แปลง|CPC|ค่าคลิก|ต้นทุน|กลุ่มเป้าหมาย|ข้อเสนอ|ครีเอทีฟ|CTR|คลิก|ซื้อ/iu';
        $add('diagnose', "$label: สาเหตุสอดคล้องกับสัญญาณ (" . (implode(',', $codes) ?: 'ROAS ต่ำกว่าเป้า') . ')', (bool)preg_match($re, $cause), $cause);
        $steps = $d['next_steps'];
        $add('diagnose', "$label: ขั้นตอน 2–3 ข้อ", count($steps) >= 2 && count($steps) <= 3, count($steps) . ' ข้อ');
        $wrong = [];
        foreach (PLATFORM_WORDS as $p => $rx) if ($p !== $c['platform'] && preg_match($rx, implode(' ', $steps) . ' ' . $cause)) $wrong[] = $p;
        $add('diagnose', "$label: ไม่พูดถึงแพลตฟอร์มอื่น", !$wrong, implode(',', $wrong) . ' ' . implode(' | ', $steps));
        $stepSets[$label] = $steps;
    }
    $flat = array_merge(...array_values($stepSets ?: [[]]));
    $uniq = count(array_unique($flat));
    $add('diagnose', 'ขั้นตอนไม่ซ้ำกันข้ามแคมเปญ (≥90% ไม่ซ้ำ)', $flat && $uniq / count($flat) >= 0.9, "$uniq/" . count($flat));

    // ================= Next best action =================
    $nba = $res['next_best_action'];
    $products = [];
    foreach ($res['products'] as $p) $products[$p['name']] = $p;
    $gapPairs = [];
    foreach ($nba['gaps'] as $g) foreach ($g['missing'] as $ch) $gapPairs["{$g['product']}|$ch"] = true;
    $add('nba', 'ใช้ AI ในเครื่องเสนอแคมเปญ', $nba['source'] === 'local', $nba['source']);
    $props = $nba['proposals'];
    $add('nba', 'มีข้อเสนอ 1–3 รายการ', count($props) >= 1 && count($props) <= 3, count($props) . ' รายการ');
    $pairs = array_map(fn($p) => "{$p['product']}|{$p['platform']}", $props);
    $add('nba', 'ทุกข้อเสนอเป็นช่องว่างจริง (สินค้า×ช่องทางที่ยังไม่ยิง)', !array_diff($pairs, array_keys($gapPairs)), implode(', ', array_diff($pairs, array_keys($gapPairs))));
    $add('nba', 'ไม่เสนอซ้ำคู่เดิม', count($pairs) === count(array_unique($pairs)));
    $budget = array_sum(array_column($props, 'daily_budget'));
    $cap = ($nba['avg_daily_spend'] ?? 0) * 0.3;
    $add('nba', 'งบรวม ≤ 30% ของงบเฉลี่ยต่อวัน', $budget > 0 && $budget <= $cap + 1, "$budget / cap " . round($cap));

    // numbers allowed in a rationale: those in the input, rounded as shown
    $allowed = [];
    $addNum = function ($v) use (&$allowed) { if ($v === null) return; foreach ([0, 1, 2] as $dp) $allowed[] = round((float)$v, $dp); };
    foreach ($res['platforms'] as $p) foreach (['roas', 'cpa', 'ctr', 'cpc', 'spend', 'revenue'] as $k) $addNum($p[$k] ?? null);
    foreach ($res['campaigns'] as $c) foreach (['roas', 'cpa'] as $k) $addNum($c[$k] ?? null);
    foreach ($res['products'] as $p) { $addNum($p['price']); $addNum($p['margin_pct']); }
    $addNum($nba['avg_daily_spend']); $addNum(3.0); $addNum(1.5);
    $platRoas = [];
    foreach ($res['platforms'] as $p) $platRoas[$p['platform']] = $p['roas'];

    foreach ($props as $p) {
        $label = "{$p['product']} @ {$p['platform']}";
        $add('nba', "$label: ชื่อสินค้าตรงกับรายการ", isset($products[$p['product']]));
        $h = $p['headlines'];
        $add('nba', "$label: พาดหัว 3 แบบ ไม่ว่าง ไม่ซ้ำ", count($h) === 3 && count(array_unique(array_filter(array_map('trim', $h)))) === 3, implode(' | ', $h));
        if ($p['platform'] === 'Google') {
            $long = array_filter($h, fn($x) => mb_strlen($x) > 30);
            $add('nba', "$label: พาดหัว Google ≤ 30 ตัวอักษร", !$long, implode(' | ', $long));
        }
        $claims = array_filter($h, fn($x) => preg_match(CLAIM_RE, $x));
        $add('nba', "$label: พาดหัวไม่อ้างผลลัพธ์/ระยะเวลาที่พิสูจน์ไม่ได้", !$claims, implode(' | ', $claims));
        $price = $products[$p['product']]['price'] ?? null;
        $badNum = [];
        foreach ($h as $x) { preg_match_all('/\d[\d,]*(\.\d+)?/', $x, $mm); foreach ($mm[0] as $n) { $n = (float)str_replace(',', '', $n); if ($price === null || abs($n - $price) > 0.5) $badNum[] = $n; } }
        $add('nba', "$label: ตัวเลขในพาดหัวมีแค่ราคาสินค้า", !$badNum, implode(',', $badNum));
        preg_match_all('/\d[\d,]*(\.\d+)?/', $p['rationale'], $mm);
        $bad = [];
        foreach ($mm[0] as $n) { $v = (float)str_replace(',', '', $n); $ok = false; foreach ($allowed as $a) if (abs($a - $v) < 0.006) { $ok = true; break; } if (!$ok) $bad[] = $n; }
        $add('nba', "$label: ตัวเลขในเหตุผลมาจากข้อมูลจริง", !$bad, implode(',', $bad) . ' — ' . $p['rationale']);
        // every "<Platform> … ROAS x" claim in the rationale must be that platform's ROAS
        $wrongClaim = [];
        if (preg_match_all('/(Meta|Google|TikTok)[^0-9;—]{0,40}?ROAS[^0-9]{0,15}(\d+(?:\.\d+)?)/u', $p['rationale'], $cm, PREG_SET_ORDER))
            foreach ($cm as $m) if (isset($platRoas[$m[1]]) && abs(round($platRoas[$m[1]], 2) - (float)$m[2]) > 0.01) $wrongClaim[] = "$m[1] ROAS $m[2]";
        $add('nba', "$label: อ้าง ROAS ของแพลตฟอร์มถูกต้อง", !$wrongClaim, implode(', ', $wrongClaim));
        $promo = array_filter($h, fn($x) => preg_match('/ลดราคา|ส่วนลด|ลด\s*\d|โปร(โมชั่น)?|แถม|ฟรี|sale|off\b/iu', $x));
        $add('nba', "$label: พาดหัวไม่แต่งโปร/ส่วนลดที่ไม่มีในข้อมูล", !$promo, implode(' | ', $promo));
        $mangled = array_filter($h, fn($x) => preg_match('/วอลเลต|วอลเล็ท|เวลเว็ต|เวลเวท|ไฮดร้า|โกลว์|ไนท์|แอคเน่|คอลลาเจน/u', $x));
        $add('nba', "$label: ไม่แปลง/ถอดเสียงชื่อสินค้าจนเพี้ยน", !$mangled, implode(' | ', $mangled));
        $ints = $p['audience']['interests'] ?? [];
        $other = array_filter($ints, fn($x) => array_filter(PLATFORM_WORDS, fn($rx, $k) => $k !== $p['platform'] && preg_match($rx, $x), ARRAY_FILTER_USE_BOTH));
        $add('nba', "$label: interests ไม่อ้างแพลตฟอร์มอื่น", !$other, implode(', ', $other));
        $add('nba', "$label: interests ไม่ซ้ำกัน", count($ints) === count(array_unique(array_map(fn($x) => mb_strtolower(trim($x)), $ints))), implode(', ', $ints));
        $age = $p['audience']['age'] ?? '';
        $add('nba', "$label: ช่วงอายุเป็นรูปแบบ NN-NN", (bool)preg_match('/^\d{2}\s*-\s*\d{2}\+?$/', $age), $age);
        $add('nba', "$label: interests 3–6 รายการ", count($p['audience']['interests'] ?? []) >= 3 && count($p['audience']['interests']) <= 6, count($p['audience']['interests'] ?? []));
    }

    // the creative angle must belong to its own proposal (a copied angle = attached to the wrong product)
    $angles = array_values(array_filter(array_map(fn($p) => preg_match('/แนวครีเอทีฟ:\s*(.+)$/u', $p['rationale'], $m) ? trim($m[1]) : null, $props)));
    $add('nba', 'แนวครีเอทีฟไม่ซ้ำข้ามข้อเสนอ', count($angles) === count(array_unique($angles)), implode(' | ', $angles));
    // steps inside one campaign must not say the same thing twice
    foreach ($stepSets as $label => $steps) {
        $dup = false;
        for ($a = 0; $a < count($steps); $a++) for ($b = $a + 1; $b < count($steps); $b++) if (sim($steps[$a], $steps[$b]) >= 0.45) $dup = true;
        $add('diagnose', "$label: ขั้นตอนในแคมเปญไม่ซ้ำความหมาย", !$dup, implode(' | ', $steps));
    }

    $pass = count(array_filter($checks, fn($c) => $c['ok']));
    $all[] = ['run' => $i, 'set' => $set, 'passed' => $pass, 'total' => count($checks), 'seconds' => round(microtime(true) - $t0, 1), 'timing' => $res['timing'], 'issues' => $res['issues'], 'checks' => $checks];
    printf("run %d [%s] %d/%d checks  (%.0fs: diagnose %.0fs, nba %.0fs)\n", $i, $set, $pass, count($checks), microtime(true) - $t0, $res['timing']['diagnose'], $res['timing']['next_best_action']);
    foreach ($checks as $c) if (!$c['ok'] || $verbose) echo '   ' . ($c['ok'] ? 'ok ' : 'XX ') . "[{$c['group']}] {$c['name']}  " . mb_substr($c['detail'], 0, 220) . "\n";
    foreach ($res['issues'] as $is) echo "   issue: $is\n";
}
$P = array_sum(array_column($all, 'passed')); $T = array_sum(array_column($all, 'total'));
printf("\nTOTAL %d/%d checks (%.1f%%), %d/%d runs fully clean\n", $P, $T, $P / max(1, $T) * 100, count(array_filter($all, fn($r) => $r['passed'] === $r['total'])), count($all));
file_put_contents(RUNS_DIR . '/ai-quality-latest.json', json_encode(['at' => date('c'), 'engine' => Llm::label(), 'passed' => $P, 'total' => $T, 'runs' => $all], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
