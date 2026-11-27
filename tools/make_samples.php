<?php
// Generates the demo files in samples/ (deterministic). Run: php tools/make_samples.php
mt_srand(42);
$dir = __DIR__ . '/../samples';
$days = [];
for ($i = 0; $i < 14; $i++) $days[] = date('Y-m-d', strtotime("2026-11-01 +$i day"));
$noise = fn($x, $p = 0.12) => $x * (1 + (mt_rand(-1000, 1000) / 1000) * $p);

// ---------- Meta (THB, Thai-style export) ----------
// [name, daily spend, CTR%, CVR%, AOV, fatigue?]
$meta = [
    ['GlowSerum_Conversion_Broad', 4200, 1.9, 3.4, 890, false],
    ['GlowSerum_Retarget_30D', 1500, 2.8, 6.5, 920, false],
    ['SunScreen_Reels_Lookalike', 3000, 2.2, 2.6, 450, true],
    ['AcneGel_Engagement_Test', 1200, 1.1, 0.9, 390, false],
];
$f = fopen("$dir/meta_ads_export.csv", 'w');
fwrite($f, "\xEF\xBB\xBF");
fputcsv($f, ['Reporting starts', 'Reporting ends', 'Campaign name', 'Amount spent (THB)', 'Impressions', 'Reach', 'Frequency', 'Link clicks', 'CTR (link click-through rate)', 'Purchases', 'Purchases conversion value', 'Cost per result']);
foreach ($meta as [$name, $sp, $ctr, $cvr, $aov, $fat]) {
    foreach ($days as $i => $d) {
        $spend = round($noise($sp), 2);
        $cpm = 95;
        $impr = (int)round($spend / $cpm * 1000);
        $freq = $fat ? 1.6 + $i * 0.32 : 1.4 + $i * 0.05;
        $c = $fat ? $ctr * (1 - $i * 0.05) : $ctr;              // CTR decays for the fatigued campaign
        $clicks = (int)round($impr * $noise($c, 0.08) / 100);
        $cv = $fat ? $cvr * (1 - $i * 0.02) : $cvr;
        $purch = (int)round($clicks * $noise($cv, 0.15) / 100);
        $rev = round($purch * $noise($aov, 0.05), 2);
        fputcsv($f, [$d, $d, $name, number_format($spend, 2, '.', ''), $impr, (int)($impr / $freq), round($freq, 2), $clicks,
            round($clicks / max(1, $impr) * 100, 2), $purch ?: '', $rev ?: '', $purch ? round($spend / $purch, 2) : '']);
    }
}
fclose($f);

// ---------- Google Ads (USD, title rows, quoted thousands) ----------
$google = [
    ['Search - Brand - GlowSerum', 45, 8.5, 9.0, 27],
    ['PMax - Skincare - All Products', 120, 1.4, 3.2, 21],
    ['Search - Generic - Sunscreen', 70, 4.2, 4.5, 14],
];
$f = fopen("$dir/google_ads_report.csv", 'w');
fwrite($f, "Campaign report\n\"November 1, 2026 - November 14, 2026\"\n");
fputcsv($f, ['Day', 'Campaign', 'Campaign status', 'Currency code', 'Cost', 'Impr.', 'Clicks', 'CTR', 'Avg. CPC', 'Conversions', 'Conv. value', 'Cost / conv.']);
foreach ($google as $gi => [$name, $sp, $ctr, $cvr, $aov]) {
    foreach ($days as $i => $d) {
        $spend = round($noise($sp), 2);
        $clicks = (int)round($spend / $noise($ctr > 5 ? 0.35 : 0.55, 0.1));
        $impr = (int)round($clicks / ($noise($ctr, 0.08) / 100));
        $cv = $cvr;
        if ($gi === 2 && $i >= 7) $cv = $cvr * 0.35;                // conversion rate collapses → CPA spike
        $conv = round($clicks * $noise($cv, 0.15) / 100, 2);
        $val = round($conv * $noise($aov, 0.05), 2);
        fputcsv($f, [$d, $name, 'Enabled', 'USD', number_format($spend, 2), number_format($impr), number_format($clicks),
            round($clicks / max(1, $impr) * 100, 2) . '%', '$' . round($spend / max(1, $clicks), 2), $conv, number_format($val, 2),
            $conv > 0 ? round($spend / $conv, 2) : '--']);
    }
}
fputcsv($f, ['Total: Account', '', '', 'USD', '', '', '', '', '', '', '', '']);
fclose($f);

// ---------- TikTok (JSON API style, nested) ----------
$tiktok = [
    ['TT_SunScreen_SparkAds_GenZ', 2500, 1.3, 2.4, 450],
    ['TT_GlowSerum_VSA_Shop', 3200, 1.0, 1.9, 870],
    ['TT_AcneGel_Awareness_TopView', 1800, 0.6, 0.0, 390],
];
$list = [];
foreach ($tiktok as [$name, $sp, $ctr, $cvr, $aov]) {
    foreach ($days as $d) {
        $spend = round($noise($sp), 2);
        $impr = (int)round($spend / 60 * 1000);
        $clicks = (int)round($impr * $noise($ctr, 0.1) / 100);
        $conv = (int)round($clicks * $noise($cvr, 0.2) / 100);
        $list[] = [
            'dimensions' => ['campaign_name' => $name, 'stat_time_day' => "$d 00:00:00"],
            'metrics' => ['spend' => (string)$spend, 'impressions' => (string)$impr, 'clicks' => (string)$clicks,
                'ctr' => (string)round($clicks / max(1, $impr) * 100, 2), 'complete_payment' => (string)$conv,
                'total_complete_payment_rate' => (string)round($conv * $noise($aov, 0.05), 2), 'currency' => 'THB'],
        ];
    }
}
file_put_contents("$dir/tiktok_ads.json", json_encode(['code' => 0, 'message' => 'OK', 'data' => ['page_info' => ['total_number' => count($list)], 'list' => $list]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// ---------- Products ----------
file_put_contents("$dir/products.json", json_encode(['products' => [
    ['name' => 'Glow Serum Vitamin C', 'price' => 890, 'margin_pct' => 70, 'audience' => 'ผู้หญิง 25-40, ผิวหมองคล้ำ, skincare, beauty', 'channels' => ['Meta', 'Google', 'TikTok']],
    ['name' => 'UV Shield Sunscreen SPF50', 'price' => 450, 'margin_pct' => 65, 'audience' => 'วัยรุ่น-วัยทำงาน 18-35, กิจกรรมกลางแจ้ง, ทะเล', 'channels' => ['Meta', 'Google', 'TikTok']],
    ['name' => 'Acne Clear Gel', 'price' => 390, 'margin_pct' => 72, 'audience' => 'วัยรุ่น 16-25, ปัญหาสิว', 'channels' => ['Meta', 'TikTok']],
    ['name' => 'Night Repair Cream', 'price' => 1290, 'margin_pct' => 75, 'audience' => 'ผู้หญิง 30-50, ริ้วรอย, anti-aging', 'channels' => []],
    ['name' => 'Gift Set ปีใหม่ (Serum + Sunscreen)', 'price' => 1190, 'margin_pct' => 60, 'audience' => 'ซื้อเป็นของขวัญ, ผู้ชายซื้อให้แฟน, เทศกาลปีใหม่', 'channels' => ['Meta']],
]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "samples written\n";
