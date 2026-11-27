<?php
/** Runs the 4 steps and saves everything under runs/<id>/ */
class Pipeline
{
    public static function run(array $files, string $productsText, array $opt = []): array
    {
        $opt += ['ai' => true, 'report_mode' => 'template', 'png' => true, 'settings' => null, 'id' => null];
        $settings = $opt['settings'] ?? app_settings();
        $useAi = $opt['ai'] && Llm::available();
        $t0 = microtime(true);
        $id = $opt['id'] ?? date('Ymd-His') . '-' . bin2hex(random_bytes(2));
        $dir = RUNS_DIR . "/$id";
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $timing = [];

        // 1 — Normalize
        $t = microtime(true);
        $norm = new Normalizer($settings);
        $rows = $norm->run($files, $useAi);
        $invalid = [];
        foreach ($rows as $i => $r) if ($e = Normalizer::validateRow($r)) $invalid[] = ['row' => $i, 'errors' => $e];
        $timing['normalize'] = round(microtime(true) - $t, 2);
        if (!$rows) throw new RuntimeException('ไม่มีข้อมูลแคมเปญที่ใช้ได้: ' . implode(' | ', $norm->issues));

        // 2 — Diagnose
        $t = microtime(true);
        $agg = Metrics::aggregate($rows);
        $dg = new Diagnostics();
        $diag = $dg->run($agg, $settings, $useAi);
        $timing['diagnose'] = round(microtime(true) - $t, 2);

        // 3 — Next best action
        $t = microtime(true);
        $products = NextBestAction::parseProducts($productsText);
        $nb = new NextBestAction();
        $nba = $nb->run($products, $agg, $diag, $settings, $useAi);
        $timing['next_best_action'] = round(microtime(true) - $t, 2);

        // 4 — Visual report
        $t = microtime(true);
        $rr = new ReportRenderer();
        $data = ReportRenderer::reportData($agg, $diag, $nba, $settings);
        $reportIssues = [];
        $mode = 'template';
        $html = $rr->template($data);
        $fit = null;
        if ($opt['report_mode'] === 'claude' && $useAi && !Llm::canDesignHtml()) {
            $reportIssues[] = 'LLM ในเครื่องไม่ได้ออกแบบ HTML รายงาน (ยาวและต้องจัดเลย์เอาต์แม่นเกินโมเดลเล็ก) — ใช้เทมเพลตมาตรฐาน';
        } elseif ($opt['report_mode'] === 'claude' && $useAi) {
            try {
                $aiHtml = $rr->claudeHtml($data);
                file_put_contents("$dir/report_claude.html", $aiHtml);
                $fit = Renderer::check("$dir/report_claude.html");
                if ($fit['fits']) { $html = $aiHtml; $mode = 'claude'; }
                else $reportIssues[] = 'HTML จาก Claude ไม่ผ่านการตรวจ (ขนาด ' . $fit['w'] . 'x' . $fit['h'] . ($fit['clipped'] ? ', ล้นกรอบ: ' . implode(',', $fit['clipped']) : '') . ') — ใช้เทมเพลตแทน';
            } catch (Throwable $e) {
                $reportIssues[] = 'Claude สร้าง HTML ไม่สำเร็จ ใช้เทมเพลตแทน (' . $e->getMessage() . ')';
            }
        }
        file_put_contents("$dir/report.html", $html);
        if ($mode === 'template' || !$fit) $fit = Renderer::edge() ? Renderer::check("$dir/report.html") : null;
        $png = false;
        if ($opt['png'] && Renderer::edge()) $png = Renderer::png("$dir/report.html", "$dir/report.png");
        $timing['report'] = round(microtime(true) - $t, 2);

        // strip daily series from what we store per campaign (kept in normalized.json)
        $campaigns = array_map(function ($c) { unset($c['daily']); return $c; }, $agg['campaigns']);
        // "AI used" only if at least one Claude step actually succeeded
        $useAi = $useAi && (in_array('claude', array_column($norm->mappings, 'source'), true)
            || array_filter(array_column($diag['campaigns'], 'ai')) || in_array($nba['source'], ['claude', 'local'], true) || in_array('local', array_column($norm->mappings, 'source'), true) || $mode === 'claude');
        $result = [
            'id' => $id,
            'created' => date('c'),
            'ai_used' => $useAi,
            'model' => $useAi ? Llm::label() : null,
            'settings' => ['base_currency' => $settings['base_currency'], 'target_roas' => $settings['target_roas'], 'breakeven_roas' => $settings['breakeven_roas'], 'fx' => $settings['fx']],
            'files' => array_column($files, 'name'),
            'mappings' => $norm->mappings,
            'row_count' => count($rows),
            'invalid_rows' => $invalid,
            'issues' => array_values(array_unique(array_merge($norm->issues, $dg->issues, $nb->issues, $reportIssues))),
            'totals' => $agg['totals'],
            'period' => [$agg['date_from'], $agg['date_to']],
            'platforms' => array_values($agg['platforms']),
            'campaigns' => array_values($campaigns),
            'diagnostics' => $diag,
            'products' => $products,
            'next_best_action' => $nba,
            'report' => ['mode' => $mode, 'fit' => $fit, 'png' => $png],
            'timing' => $timing + ['total' => round(microtime(true) - $t0, 2)],
        ];
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
        file_put_contents("$dir/normalized.json", json_encode($rows, $flags));
        file_put_contents("$dir/result.json", json_encode($result, $flags));
        return $result;
    }

    public static function load(string $id): ?array
    {
        if (!preg_match('/^[\w-]+$/', $id)) return null;
        $f = RUNS_DIR . "/$id/result.json";
        return is_file($f) ? json_decode(file_get_contents($f), true) : null;
    }

    public static function history(int $n = 15): array
    {
        $dirs = array_filter(glob(RUNS_DIR . '/*/result.json'), fn($f) => !str_starts_with(basename(dirname($f)), 'eval-'));
        rsort($dirs);
        $out = [];
        foreach (array_slice($dirs, 0, $n) as $f) {
            $r = json_decode(file_get_contents($f), true);
            if ($r) $out[] = $r;
        }
        return $out;
    }
}
