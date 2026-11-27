<?php
/**
 * Step 4 — Visual Report Generator (HTML + Tailwind, 1080x1350).
 * Two modes:
 *  - template: fixed layout built in PHP, sized so it always fits the frame (default, guaranteed)
 *  - claude:   Claude writes the HTML (prompts/4_visual_report.md); if the headless check finds it
 *              overflows or Tailwind didn't load, the template version is used instead.
 */
class ReportRenderer
{
    public const TAILWIND = '<script src="../../assets/tailwind.js"></script>';
    public array $issues = [];

    public static function money($v, string $cur): string
    {
        if ($v === null) return '—';
        $sym = ['THB' => '฿', 'USD' => '$', 'EUR' => '€', 'JPY' => '¥'][$cur] ?? '';
        return $sym . self::compact($v);
    }

    public static function compact($v): string
    {
        if ($v === null) return '—';
        $a = abs($v);
        if ($a >= 1e6) return number_format($v / 1e6, 2) . 'M';
        if ($a >= 1e4) return number_format($v / 1e3, 1) . 'K';
        return number_format($v, $a >= 100 ? 0 : 2);
    }

    /** Data handed to both the template and Claude. */
    public static function reportData(array $agg, array $diag, array $nba, array $settings): array
    {
        $cnt = array_count_values(array_column($diag['campaigns'], 'status')) + ['green' => 0, 'yellow' => 0, 'red' => 0];
        $camps = [];
        foreach ($agg['campaigns'] as $id => $c) {
            $d = $diag['campaigns'][$id];
            $camps[] = ['name' => $c['campaign_name'], 'platform' => $c['platform'], 'spend' => $c['spend'], 'revenue' => $c['revenue'],
                'roas' => $c['roas'], 'cpa' => $c['cpa'], 'conversions' => $c['conversions'], 'status' => $d['status'], 'action' => $d['action'],
                'top_flag' => $d['flags'][0]['text'] ?? ''];
        }
        return [
            'currency' => $settings['base_currency'],
            'period' => [$agg['date_from'], $agg['date_to']],
            'totals' => Diagnostics::strip($agg['totals']),
            'status_count' => ['green' => $cnt['green'], 'yellow' => $cnt['yellow'], 'red' => $cnt['red']],
            'platforms' => array_values(array_map(fn($p) => ['platform' => $p['platform']] + Diagnostics::strip($p), $agg['platforms'])),
            'campaigns' => $camps,
            'summary' => $diag['summary'],
            'next_actions' => array_map(fn($p) => ['product' => $p['product'], 'platform' => $p['platform'], 'objective' => $p['objective'],
                'daily_budget' => $p['daily_budget'], 'headline' => $p['headlines'][0] ?? '', 'priority' => $p['priority']], array_slice($nba['proposals'], 0, 3)),
            'target_roas' => $settings['target_roas'],
        ];
    }

    public function template(array $d): string
    {
        $cur = $d['currency'];
        $t = $d['totals'];
        $period = $d['period'][0] ? h(date('j M Y', strtotime($d['period'][0])) . ' – ' . date('j M Y', strtotime($d['period'][1]))) : 'ทุกช่วงเวลา';
        $statusColor = ['green' => 'bg-emerald-500', 'yellow' => 'bg-amber-400', 'red' => 'bg-rose-500'];
        $statusChip = ['green' => 'bg-emerald-50 text-emerald-700 ring-emerald-200', 'yellow' => 'bg-amber-50 text-amber-700 ring-amber-200', 'red' => 'bg-rose-50 text-rose-700 ring-rose-200'];
        $actionTh = ['scale' => 'ขยายงบ', 'optimize' => 'ปรับปรุง', 'pause' => 'หยุด/ลดงบ', 'review' => 'ตรวจสอบ'];
        $platColor = ['Meta' => '#2563eb', 'Google' => '#16a34a', 'TikTok' => '#0f172a', 'Other' => '#64748b'];

        $kpi = function ($label, $value, $sub = '') {
            return '<div class="rounded-2xl bg-white/10 px-6 py-4"><div class="text-[19px] text-slate-300">' . h($label) . '</div>'
                . '<div class="mt-1 text-[40px] font-bold leading-tight text-white">' . h($value) . '</div>'
                . '<div class="text-[17px] text-slate-400 truncate">' . h($sub) . '</div></div>';
        };
        $roasTxt = $t['roas'] === null ? '—' : number_format($t['roas'], 2) . 'x';

        // Platform bars
        $plats = array_slice($d['platforms'], 0, 4);
        $max = max(1, ...array_map(fn($p) => max($p['spend'], $p['revenue']), $plats ?: [['spend' => 1, 'revenue' => 1]]));
        $bars = '';
        foreach ($plats as $p) {
            $ws = round($p['spend'] / $max * 100, 1);
            $wr = round($p['revenue'] / $max * 100, 1);
            $roas = $p['roas'] === null ? '—' : number_format($p['roas'], 2) . 'x';
            $good = $p['roas'] !== null && $p['roas'] >= $d['target_roas'];
            $bars .= '<div class="flex items-center gap-5">'
                . '<div class="w-[120px] flex items-center gap-2 text-[22px] font-semibold text-slate-800"><span class="inline-block h-3 w-3 rounded-full" style="background:' . ($platColor[$p['platform']] ?? '#64748b') . '"></span>' . h($p['platform']) . '</div>'
                . '<div class="flex-1 space-y-1">'
                . '<div class="flex items-center gap-3"><div class="h-[14px] rounded-r-md bg-slate-300" style="width:' . max($ws, 0.5) . '%"></div><span class="text-[15px] leading-[22px] text-slate-500 whitespace-nowrap">งบ ' . h(self::money($p['spend'], $cur)) . '</span></div>'
                . '<div class="flex items-center gap-3"><div class="h-[14px] rounded-r-md bg-indigo-500" style="width:' . max($wr, 0.5) . '%"></div><span class="text-[15px] leading-[22px] text-slate-700 whitespace-nowrap">รายได้ ' . h(self::money($p['revenue'], $cur)) . '</span></div>'
                . '</div>'
                . '<div class="w-[120px] text-right"><div class="text-[26px] leading-none font-bold ' . ($good ? 'text-emerald-600' : 'text-slate-800') . '">' . $roas . '</div><div class="mt-1 text-[13px] text-slate-400">ROAS</div></div>'
                . '</div>';
        }
        // the bars are scaled to the widest value, so keep a little room for the label
        $bars = str_replace('style="width:', 'style="max-width:62%;width:', $bars);

        // Campaign rows
        $rowsMax = 6;
        $rows = '';
        foreach (array_slice($d['campaigns'], 0, $rowsMax) as $c) {
            $rows .= '<div class="flex items-center gap-4 border-b border-slate-100 py-[6px]">'
                . '<span class="h-4 w-4 shrink-0 rounded-full ' . $statusColor[$c['status']] . '"></span>'
                . '<div class="min-w-0 flex-1"><div class="truncate text-[19px] leading-[26px] font-semibold text-slate-800">' . h($c['name']) . '</div>'
                . '<div class="truncate text-[15px] leading-[20px] text-slate-500">' . h($c['platform']) . ($c['top_flag'] ? ' · ' . h($c['top_flag']) : '') . '</div></div>'
                . '<div class="w-[120px] text-right text-[20px] text-slate-700">' . h(self::money($c['spend'], $cur)) . '</div>'
                . '<div class="w-[90px] text-right text-[20px] font-semibold text-slate-900">' . ($c['roas'] === null ? '—' : number_format($c['roas'], 2) . 'x') . '</div>'
                . '<div class="w-[110px] text-right text-[20px] text-slate-700">' . h(self::money($c['cpa'], $cur)) . '</div>'
                . '<div class="w-[120px] text-right"><span class="inline-block rounded-full px-3 py-1 text-[16px] font-semibold ring-1 ' . $statusChip[$c['status']] . '">' . $actionTh[$c['action']] . '</span></div>'
                . '</div>';
        }
        $more = count($d['campaigns']) - $rowsMax;
        $moreTxt = $more > 0 ? '<div class="pt-2 text-[16px] text-slate-400">+ อีก ' . $more . ' แคมเปญ (ดูรายละเอียดในระบบ)</div>' : '';

        // Next best actions
        $nba = '';
        foreach (array_slice($d['next_actions'], 0, 2) as $n) {
            $nba .= '<div class="flex-1 min-w-0 rounded-2xl bg-indigo-50 px-5 py-4 ring-1 ring-indigo-100">'
                . '<div class="flex items-center justify-between gap-2"><span class="truncate text-[20px] font-bold text-indigo-900">' . h($n['product']) . '</span>'
                . '<span class="shrink-0 rounded-full bg-indigo-600 px-3 py-0.5 text-[15px] font-semibold text-white">' . h($n['platform']) . '</span></div>'
                . '<div class="mt-1 truncate text-[16px] text-indigo-700">' . h($n['objective']) . ' · ' . h(self::money($n['daily_budget'], $cur)) . '/วัน</div>'
                . '<div class="mt-1 truncate text-[17px] leading-[24px] text-slate-700">“' . h($n['headline']) . '”</div></div>';
        }
        if ($nba === '') $nba = '<div class="text-[18px] text-slate-400">ไม่มีข้อเสนอแคมเปญใหม่ (ยังไม่ได้ใส่ข้อมูลสินค้า หรือทุกสินค้ายิงครบทุกช่องทางแล้ว)</div>';

        $sc = $d['status_count'];
        $body = <<<HTML
<div class="w-[1080px] h-[1350px] overflow-hidden bg-slate-50 flex flex-col" style="font-family:'Leelawadee UI','Noto Sans Thai',Tahoma,sans-serif">
  <section data-fit-box="header" class="h-[400px] shrink-0 overflow-hidden bg-slate-900 px-14 pt-10 pb-8">
    <div class="flex items-start justify-between">
      <div><div class="text-[20px] font-semibold uppercase tracking-[0.2em] text-indigo-300">Multi-Channel Ads Report</div>
      <div class="mt-2 text-[44px] font-bold text-white leading-tight">สรุปผลโฆษณาข้ามแพลตฟอร์ม</div>
      <div class="mt-1 text-[21px] text-slate-400">{$period}</div></div>
      <div class="flex gap-2 pt-2">
        <span class="rounded-full bg-emerald-500/20 px-4 py-1.5 text-[18px] font-semibold text-emerald-300">● ขยาย {$sc['green']}</span>
        <span class="rounded-full bg-amber-400/20 px-4 py-1.5 text-[18px] font-semibold text-amber-300">● ปรับ {$sc['yellow']}</span>
        <span class="rounded-full bg-rose-500/20 px-4 py-1.5 text-[18px] font-semibold text-rose-300">● หยุด {$sc['red']}</span>
      </div>
    </div>
    <div class="mt-7 grid grid-cols-4 gap-4">
HTML;
        $body .= $kpi('งบที่ใช้', self::money($t['spend'], $cur), number_format($t['impressions']) . ' impressions')
            . $kpi('รายได้', self::money($t['revenue'], $cur), number_format($t['conversions']) . ' conversions')
            . $kpi('ROAS', $roasTxt, 'เป้า ' . number_format($d['target_roas'], 1) . 'x')
            . $kpi('CPA', self::money($t['cpa'], $cur), 'CTR ' . ($t['ctr'] === null ? '—' : number_format($t['ctr'], 2) . '%'));
        $summary = h($d['summary']);
        $body .= <<<HTML
    </div>
  </section>
  <section data-fit-box="platforms" class="h-[290px] shrink-0 overflow-hidden px-14 pt-6">
    <div class="mb-4 flex items-center justify-between"><div class="text-[24px] font-bold text-slate-900">เปรียบเทียบแพลตฟอร์ม</div>
      <div class="flex gap-4 text-[16px] text-slate-500"><span><span class="mr-1 inline-block h-3 w-3 rounded-sm bg-slate-300"></span>งบ</span><span><span class="mr-1 inline-block h-3 w-3 rounded-sm bg-indigo-500"></span>รายได้</span></div></div>
    <div class="space-y-[10px]">{$bars}</div>
  </section>
  <section data-fit-box="campaigns" class="h-[440px] shrink-0 overflow-hidden px-14 pt-4">
    <div class="flex items-center gap-4 border-b-2 border-slate-200 pb-2 text-[16px] font-semibold uppercase tracking-wide text-slate-400">
      <span class="w-4"></span><div class="flex-1">แคมเปญ</div><div class="w-[120px] text-right">งบ</div><div class="w-[90px] text-right">ROAS</div><div class="w-[110px] text-right">CPA</div><div class="w-[120px] text-right">คำแนะนำ</div></div>
    {$rows}{$moreTxt}
  </section>
  <section data-fit-box="next" class="h-[180px] shrink-0 overflow-hidden px-14 pt-3">
    <div class="mb-2 text-[22px] font-bold text-slate-900">แคมเปญใหม่ที่แนะนำ</div>
    <div class="flex gap-4">{$nba}</div>
  </section>
  <footer data-fit-box="footer" class="mt-auto h-[40px] shrink-0 overflow-hidden px-14 text-[15px] text-slate-400 flex items-center justify-between">
    <span class="truncate pr-6">{$summary}</span><span class="shrink-0">สกุลเงิน {$cur}</span>
  </footer>
</div>
HTML;
        return self::page($body);
    }

    public static function page(string $body): string
    {
        return "<!doctype html>\n<html lang=\"th\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=1080\">"
            . self::TAILWIND . "<title>Ads Report</title></head>\n<body class=\"m-0 p-0\">\n" . $body . "\n" . Renderer::CHECKER . "</body></html>";
    }

    /** Ask Claude for the HTML. Returns full document with local Tailwind and the fit checker injected. */
    public function claudeHtml(array $d): string
    {
        $html = Llm::text(prompt_text('4_visual_report'), json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 16000);
        if (preg_match('/```(?:html)?\s*(.*?)```/s', $html, $m)) $html = $m[1];
        if (!preg_match('/<body[^>]*>(.*)<\/body>/is', $html, $m)) throw new RuntimeException('Claude ไม่ได้ส่ง HTML ที่มี <body>');
        $inner = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $m[1]); // no external/inline scripts
        $style = preg_match_all('#<style\b[^>]*>.*?</style>#is', $html, $s) ? implode("\n", $s[0]) : '';
        return self::page($style . $inner);
    }
}
