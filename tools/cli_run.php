<?php
// CLI smoke run on the sample files. Usage: php tools/cli_run.php [--ai] [--claude-report]
require __DIR__ . '/../config.php';
$ai = in_array('--ai', $argv, true);
$files = [];
foreach (['meta_ads_export.csv', 'google_ads_report.csv', 'tiktok_ads.json'] as $n) $files[] = ['name' => $n, 'content' => file_get_contents(APP_ROOT . "/samples/$n")];
$r = Pipeline::run($files, file_get_contents(APP_ROOT . '/samples/products.json'),
    ['ai' => $ai, 'report_mode' => in_array('--claude-report', $argv, true) ? 'claude' : 'template']);
echo json_encode(['id' => $r['id'], 'rows' => $r['row_count'], 'invalid' => count($r['invalid_rows']), 'totals' => $r['totals'],
    'mappings' => array_map(fn($m) => [$m['file'], $m['platform'], $m['currency'], $m['source'], array_filter($m['map'])], $r['mappings']),
    'status' => array_map(fn($c) => [$c['campaign_name'], $c['roas'], $c['cpa'], $r['diagnostics']['campaigns'][$c['id']]['status'], array_column($r['diagnostics']['campaigns'][$c['id']]['flags'], 'code')], $r['campaigns']),
    'nba' => count($r['next_best_action']['proposals']), 'report' => $r['report'], 'issues' => array_slice($r['issues'], 0, 8), 'timing' => $r['timing']],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
