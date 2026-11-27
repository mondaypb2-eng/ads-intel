<?php
// ===== Multi-Channel Ads Intelligence — configuration =====
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Bangkok');

define('APP_ROOT', __DIR__);
define('RUNS_DIR', APP_ROOT . '/runs');
define('STORAGE_DIR', APP_ROOT . '/storage');

// Settings saved from settings.php (storage/ is blocked from the web by .htaccess)
function app_settings(): array {
    static $s = null;
    if ($s !== null) return $s;
    $defaults = [
        'provider'       => 'ollama',   // 'ollama' (local, free) | 'claude' (Anthropic API)
        'ollama'         => ['url' => 'http://127.0.0.1:11434', 'model' => 'qwen3:4b', 'num_ctx' => 8192, 'think' => false],
        'api_key'        => '',
        'model'          => 'claude-opus-5-5',
        'effort'         => 'medium',
        'base_currency'  => 'THB',
        // Conversion rates INTO the base currency (1 unit of X = rate THB). Edit in Settings.
        'fx'             => ['THB' => 1, 'USD' => 34.0, 'EUR' => 37.0, 'SGD' => 25.5, 'JPY' => 0.23],
        'target_roas'    => 3.0,
        'breakeven_roas' => 1.5,
        'edge_path'      => 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    ];
    $file = STORAGE_DIR . '/settings.json';
    $saved = is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    $s = array_replace($defaults, $saved);
    $s['ollama'] = array_replace($defaults['ollama'], $saved['ollama'] ?? []);
    if (!$s['api_key'] && getenv('ANTHROPIC_API_KEY')) $s['api_key'] = getenv('ANTHROPIC_API_KEY');
    return $s;
}

function save_settings(array $new): void {
    $file = STORAGE_DIR . '/settings.json';
    $cur = is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    file_put_contents($file, json_encode(array_replace_recursive($cur, $new), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

spl_autoload_register(function ($c) {
    $f = APP_ROOT . '/lib/' . $c . '.php';
    if (is_file($f)) require $f;
});

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function prompt_text(string $name): string { return file_get_contents(APP_ROOT . "/prompts/$name.md"); }
