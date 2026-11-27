<?php
/**
 * Eval suite. Pass criteria (from the brief):
 *  1. Output JSON never breaks: 100% of rows pass the schema and the result encodes/decodes cleanly.
 *  2. ROAS / CPA (and totals) exactly match hand-computed expected values (tolerance 0.005).
 *  3. The generated HTML report fits 1080x1350 when rendered in a real headless browser.
 */
class Evaluator
{
    // Fixed FX so expected values don't depend on what the user sets in Settings
    public const FX = ['THB' => 1, 'USD' => 34.0, 'EUR' => 37.0, 'SGD' => 25.5, 'JPY' => 0.23];

    public static function cases(): array
    {
        return [
            'missing_values' => [
                'title' => 'ค่า Missing (spend ว่าง, "N/A", "-", แถวไม่มีชื่อ)',
                'files' => ['missing_values_meta.csv'],
                'expect' => [
                    'rows' => 3,
                    'totals' => ['spend' => 1500, 'revenue' => 6500, 'conversions' => 15, 'roas' => 4.3333, 'cpa' => 100],
                    'campaigns' => [
                        'Camp A' => ['roas' => 5.0, 'cpa' => 100.0],
                        'Camp B' => ['roas' => null, 'cpa' => 0.0],
                        'Camp C' => ['roas' => 0.0, 'cpa' => null],
                    ],
                ],
            ],
            'mixed_currency' => [
                'title' => 'สกุลเงินปนกัน (USD / THB / € สัญลักษณ์ / SGD)',
                'files' => ['mixed_currency_google.csv'],
                'expect' => [
                    'rows' => 4,
                    'totals' => ['spend' => 11200, 'revenue' => 35150, 'conversions' => 8, 'roas' => 3.1384, 'cpa' => 1400],
                    'campaigns' => [
                        'US Camp' => ['spend' => 3400, 'roas' => 5.0, 'cpa' => 850],
                        'TH Camp' => ['spend' => 3400, 'roas' => 2.0, 'cpa' => 1700],
                        'EU Camp' => ['spend' => 1850, 'roas' => 2.0, 'cpa' => 1850],
                        'SG Camp' => ['spend' => 2550, 'roas' => 3.0, 'cpa' => 2550],
                    ],
                ],
            ],
            'zero_sales' => [
                'title' => 'ยอดขายเป็น 0 / งบเป็น 0 (ห้ามหารศูนย์)',
                'files' => ['zero_sales_tiktok.json'],
                'expect' => [
                    'rows' => 2,
                    'totals' => ['spend' => 1200, 'revenue' => 0, 'conversions' => 0, 'roas' => 0.0, 'cpa' => null],
                    'campaigns' => [
                        'Zero Spend' => ['roas' => null, 'cpa' => null, 'ctr' => null, 'status' => 'yellow'],
                        'Zero Sales' => ['roas' => 0.0, 'cpa' => null, 'ctr' => 0.6, 'cpc' => 4.0, 'status' => 'red'],
                    ],
                ],
            ],
            'thai_headers' => [
                'title' => 'หัวคอลัมน์ภาษาไทย + ปี พ.ศ. + ตัวเลขมีคอมมา',
                'files' => ['thai_headers.csv'],
                'expect' => [
                    'rows' => 2,
                    'totals' => ['spend' => 2000, 'revenue' => 8000, 'conversions' => 16, 'roas' => 4.0, 'cpa' => 125, 'ctr' => 2.0, 'cpc' => 2.5],
                    'campaigns' => ['แคมเปญ X' => ['roas' => 4.0, 'cpa' => 125]],
                    'period' => ['2026-11-01', '2026-11-02'],
                ],
            ],
            'stress_layout' => [
                'title' => 'ทดสอบเลย์เอาต์: 40 แคมเปญ ชื่อยาว 150 ตัวอักษร, ตัวเลขหลักล้าน',
                'files' => [],
                'generate' => 'stress',
                'expect' => ['rows' => 40],
            ],
        ];
    }

    private static function stressFile(): array
    {
        $lines = ["Campaign name,Amount spent (THB),Impressions,Link clicks,Purchases,Purchases conversion value"];
        for ($i = 1; $i <= 40; $i++) {
            $name = "แคมเปญทดสอบชื่อยาวมาก_$i" . str_repeat('_VeryLongCampaignNameForLayoutTesting', 4);
            $lines[] = '"' . mb_substr($name, 0, 150) . '",' . (123456789 * $i % 9876543 + 1000) . ',' . (98765432 + $i) . ',' . (1234567 + $i) . ',' . (1000 + $i * 37) . ',' . (987654321 % ($i * 1000003) + 5000);
        }
        return [['name' => 'stress_meta.csv', 'content' => implode("\n", $lines)]];
    }

    public static function runAll(bool $useAi = false, string $reportMode = 'template'): array
    {
        $settings = app_settings();
        $settings['fx'] = self::FX;
        $settings['base_currency'] = 'THB';
        $settings['target_roas'] = 3.0;
        $settings['breakeven_roas'] = 1.5;
        $out = [];
        foreach (self::cases() as $key => $case) {
            $out[$key] = self::runCase($key, $case, $settings, $useAi, $reportMode);
        }
        $summary = ['cases' => count($out), 'passed' => count(array_filter($out, fn($r) => $r['pass'])), 'ai' => $useAi && Llm::available(), 'engine' => Llm::label(), 'report_mode' => $reportMode, 'at' => date('c')];
        @file_put_contents(RUNS_DIR . '/eval-latest.json', json_encode(['summary' => $summary, 'cases' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return ['summary' => $summary, 'cases' => $out];
    }

    private static function runCase(string $key, array $case, array $settings, bool $useAi, string $reportMode): array
    {
        $files = isset($case['generate']) ? self::stressFile() : array_map(fn($f) => ['name' => $f, 'content' => file_get_contents(APP_ROOT . "/eval/fixtures/$f")], $case['files']);
        $checks = [];
        $add = function ($group, $name, $ok, $detail = '') use (&$checks) { $checks[] = compact('group', 'name', 'ok', 'detail'); };
        $id = "eval-$key";
        try {
            $res = Pipeline::run($files, file_get_contents(APP_ROOT . '/samples/products.json'),
                ['ai' => $useAi, 'report_mode' => $reportMode, 'settings' => $settings, 'id' => $id, 'png' => true]);
        } catch (Throwable $e) {
            $add('schema', 'pipeline รันจบ', false, $e->getMessage());
            return ['title' => $case['title'], 'pass' => false, 'checks' => $checks, 'id' => $id];
        }

        // ---- 1. Schema
        $rows = json_decode(file_get_contents(RUNS_DIR . "/$id/normalized.json"), true);
        $add('schema', 'normalized.json เป็น JSON ที่ถูกต้อง', is_array($rows), json_last_error_msg());
        $bad = 0; $firstErr = '';
        foreach ($rows ?: [] as $r) if ($e = Normalizer::validateRow($r)) { $bad++; $firstErr = $firstErr ?: implode(', ', $e); }
        $n = count($rows ?: []);
        $add('schema', 'แถวผ่าน schema 100%', $n > 0 && $bad === 0, "$n แถว, ไม่ผ่าน $bad" . ($firstErr ? " ($firstErr)" : ''));
        $add('schema', 'จำนวนแถวถูกต้อง', $n === $case['expect']['rows'], "ได้ $n คาด {$case['expect']['rows']}");
        $resJson = file_get_contents(RUNS_DIR . "/$id/result.json");
        $add('schema', 'result.json ไม่มี NaN/INF และ decode ได้', json_decode($resJson) !== null && !preg_match('/\b(NAN|INF)\b/i', $resJson));

        // ---- 2. Numbers
        $num = function ($label, $got, $exp) use ($add) {
            $ok = ($exp === null) ? $got === null : ($got !== null && abs($got - $exp) <= 0.005 + abs($exp) * 1e-6);
            $add('numbers', $label, $ok, 'ได้ ' . var_export($got, true) . ' / คาด ' . var_export($exp, true));
        };
        foreach ($case['expect']['totals'] ?? [] as $k => $v) $num("รวม $k", $res['totals'][$k], $v);
        $byName = [];
        foreach ($res['campaigns'] as $c) $byName[$c['campaign_name']] = $c;
        foreach ($case['expect']['campaigns'] ?? [] as $name => $exp) {
            if (!isset($byName[$name])) { $add('numbers', "พบแคมเปญ $name", false); continue; }
            foreach ($exp as $k => $v) {
                if ($k === 'status') {
                    $st = $res['diagnostics']['campaigns'][$byName[$name]['id']]['status'];
                    $add('numbers', "$name status", $st === $v, "ได้ $st / คาด $v");
                } else $num("$name $k", $byName[$name][$k], $v);
            }
        }
        if (isset($case['expect']['period'])) $add('numbers', 'ช่วงวันที่ (แปลง พ.ศ.)', $res['period'] === $case['expect']['period'], implode(' → ', $res['period']));

        // ---- 3. Report fits the frame
        $fit = $res['report']['fit'];
        $add('render', 'Tailwind โหลดได้', (bool)($fit['tailwind'] ?? false));
        $add('render', 'ไม่ล้นกรอบ 1080×1350', (bool)($fit['fits'] ?? false), $fit ? "{$fit['w']}×{$fit['h']}" . ($fit['clipped'] ? ' ล้นใน: ' . implode(', ', $fit['clipped']) : '') : 'ไม่มีผลวัด');
        $add('render', 'สร้าง PNG ได้', (bool)$res['report']['png']);
        if ($res['report']['png']) {
            $sz = @getimagesize(RUNS_DIR . "/$id/report.png");
            $add('render', 'PNG ขนาด 1080×1350', $sz && $sz[0] == 1080 && $sz[1] == 1350, $sz ? "{$sz[0]}×{$sz[1]}" : '');
        }

        return ['title' => $case['title'], 'pass' => !in_array(false, array_column($checks, 'ok'), true), 'checks' => $checks, 'id' => $id,
            'report_mode' => $res['report']['mode'], 'issues' => $res['issues']];
    }
}
