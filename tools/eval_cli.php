<?php
// php tools/eval_cli.php [--ai] [--claude-report]
require __DIR__ . '/../config.php';
$r = Evaluator::runAll(in_array('--ai', $argv, true), in_array('--claude-report', $argv, true) ? 'claude' : 'template');
foreach ($r['cases'] as $k => $c) {
    echo ($c['pass'] ? 'PASS' : 'FAIL') . "  $k — {$c['title']}\n";
    foreach ($c['checks'] as $ch) if (!$ch['ok'] || in_array('-v', $argv, true)) echo '      ' . ($ch['ok'] ? 'ok ' : 'XX ') . "[{$ch['group']}] {$ch['name']}  {$ch['detail']}\n";
}
echo "\n{$r['summary']['passed']}/{$r['summary']['cases']} cases passed\n";
