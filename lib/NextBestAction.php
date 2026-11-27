<?php
/**
 * Step 3 — Next-Best-Action.
 * Finds products/services × channels that are not running yet, then Claude (prompts/3_next_best_action.md)
 * proposes new campaigns with audience structure and headline ideas. Rule-based proposals when Claude is off.
 */
class NextBestAction
{
    public const CHANNELS = ['Meta', 'Google', 'TikTok'];
    public array $issues = [];

    /** products: [{name, price, margin_pct, audience, channels:[...]}] */
    public static function parseProducts(string $text): array
    {
        $text = trim($text);
        if ($text === '') return [];
        $j = json_decode($text, true);
        if (is_array($j)) {
            $list = isset($j['products']) ? $j['products'] : $j;
            return array_values(array_filter(array_map([self::class, 'cleanProduct'], $list)));
        }
        // Line format: name | price | audience | channels(comma separated)
        $out = [];
        foreach (preg_split('/\R/', $text) as $line) {
            $p = array_map('trim', explode('|', $line));
            if ($p[0] === '') continue;
            $out[] = self::cleanProduct(['name' => $p[0], 'price' => $p[1] ?? null, 'audience' => $p[2] ?? '', 'channels' => $p[3] ?? '']);
        }
        return array_values(array_filter($out));
    }

    private static function cleanProduct($p): ?array
    {
        if (!is_array($p) || trim((string)($p['name'] ?? '')) === '') return null;
        $ch = $p['channels'] ?? [];
        if (is_string($ch)) $ch = preg_split('/[,\/]+/', $ch);
        $norm = [];
        foreach ($ch as $c) {
            $c = mb_strtolower(trim($c));
            if (preg_match('/meta|facebook|fb|instagram|ig/', $c)) $norm[] = 'Meta';
            elseif (preg_match('/google|youtube|search|pmax/', $c)) $norm[] = 'Google';
            elseif (str_contains($c, 'tiktok')) $norm[] = 'TikTok';
        }
        return [
            'name' => trim($p['name']),
            'price' => is_numeric($p['price'] ?? null) ? (float)$p['price'] : null,
            'margin_pct' => is_numeric($p['margin_pct'] ?? null) ? (float)$p['margin_pct'] : null,
            'audience' => trim((string)($p['audience'] ?? '')),
            'channels' => array_values(array_unique($norm)),
        ];
    }

    public function run(array $products, array $agg, array $diag, array $settings, bool $useAi = true): array
    {
        $gaps = [];
        foreach ($products as $p) {
            $missing = array_values(array_diff(self::CHANNELS, $p['channels']));
            if ($missing) $gaps[] = ['product' => $p['name'], 'running' => $p['channels'], 'missing' => $missing];
        }
        $avgDaily = $agg['days'] > 0 ? round($agg['totals']['spend'] / $agg['days'], 2) : null;
        $context = [
            'base_currency' => $settings['base_currency'],
            'avg_daily_spend_account' => $avgDaily,
            'target_roas' => $settings['target_roas'],
            'platform_performance' => array_values(array_map(fn($p) => ['platform' => $p['platform']] + Diagnostics::strip($p), $agg['platforms'])),
            'top_campaigns' => array_slice(array_values(array_map(fn($c) => [
                'platform' => $c['platform'], 'campaign_name' => $c['campaign_name'], 'roas' => $c['roas'], 'cpa' => $c['cpa'],
                'status' => $diag['campaigns'][$c['id']]['status'] ?? null], $agg['campaigns'])), 0, 15),
            'products' => $products,
            'channel_gaps' => $gaps,
        ];

        $proposals = self::ruleProposals($products, $gaps, $agg, $avgDaily);
        $source = 'rules';
        if ($useAi && Llm::available() && $products && $gaps) {
            try {
                if (Llm::isLocal()) {
                    $proposals = $this->local($products, $gaps, $agg, $settings, $avgDaily);
                    $source = 'local';
                } else {
                    $proposals = $this->ai($context);
                    $source = 'claude';
                }
            } catch (Throwable $e) {
                $this->issues[] = (Llm::isLocal() ? 'LLM ในเครื่อง' : 'Claude') . 'เสนอแคมเปญไม่สำเร็จ ใช้ข้อเสนอจากกฎแทน (' . $e->getMessage() . ')';
            }
        }
        return ['gaps' => $gaps, 'proposals' => $proposals, 'source' => $source, 'avg_daily_spend' => $avgDaily];
    }

    // ---------- Local model ----------
    // Measured on the sample data: a 4B model asked to plan freely proposed channels that were already running,
    // copied ROAS figures from the wrong campaign/product into its rationale ("Night Repair Cream ROAS 18.12" —
    // the product had never run), and wrote health claims ("ลดสิวได้ภายใน 7 วัน"). So locally code chooses
    // WHAT to propose (a real gap, best channel first), the budget and the factual rationale; the model only
    // writes the creative parts (audience, 3 headlines, one-line angle), which are validated and backfilled.

    // result/time claims that can't be backed up, and offers the shop never announced
    public const CLAIM_RE = '/หายขาด|หายภายใน|หายเร็ว|ภายใน\s*\d+|\d+\s*(วัน|สัปดาห์|เดือน)|รับประกัน|การันตี|100\s*%|ถาวร|เห็นผลทันที|อันดับ\s*1|ดีที่สุด|ปลอดภัย\s*100'
        . '|ลดราคา|ส่วนลด|ลด\s*\d|โปร(โมชั่น)?|แถม|ฟรี|\bsale\b|\boff\b/iu';
    private const OBJECTIVE = ['Meta' => 'Sales (Advantage+ Shopping)', 'Google' => 'Sales — Search + Performance Max', 'TikTok' => 'Sales — Video Shopping Ads / Spark Ads'];

    private function local(array $products, array $gaps, array $agg, array $settings, ?float $avgDaily): array
    {
        $plat = $agg['platforms'];
        $target = (float)$settings['target_roas'];
        $breakeven = (float)$settings['breakeven_roas'];
        $byName = array_column($products, null, 'name');
        // candidates: every real gap, best account channel first; one per product before a product gets a second
        $cands = [];
        foreach ($gaps as $g) foreach ($g['missing'] as $ch) $cands[] = ['product' => $g['product'], 'platform' => $ch, 'roas' => $plat[$ch]['roas'] ?? null];
        usort($cands, fn($a, $b) => ($b['roas'] ?? -1) <=> ($a['roas'] ?? -1));
        $pick = []; $seen = [];
        foreach ([true, false] as $firstPass) foreach ($cands as $c) {
            if (count($pick) >= 3) break 2;
            $k = $c['product'] . '|' . $c['platform'];
            if (isset($seen[$k]) || ($firstPass && in_array($c['product'], array_column($pick, 'product'), true))) continue;
            $seen[$k] = true; $pick[] = $c;
        }
        // budget: total at most 30% of the account's average daily spend
        $per = $avgDaily ? max(100, floor($avgDaily * 0.3 / count($pick) / 50) * 50) : 300;
        if ($avgDaily) $per = min($per, max(100, round($avgDaily * 0.1 / 50) * 50));
        if ($avgDaily && $per * count($pick) > $avgDaily * 0.3) $per = floor($avgDaily * 0.3 / count($pick));
        $best = $plat ? array_reduce(array_keys($plat), fn($x, $k) => $x === null || ($plat[$k]['roas'] ?? 0) > ($plat[$x]['roas'] ?? 0) ? $k : $x) : null;

        $input = array_map(fn($c, $i) => ['i' => $i, 'product' => $c['product'], 'price_thb' => $byName[$c['product']]['price'] ?? null,
            'audience_notes' => $byName[$c['product']]['audience'] ?? '', 'platform' => $c['platform']], $pick, array_keys($pick));
        $schema = ['type' => 'object', 'required' => ['campaigns'], 'properties' => ['campaigns' => ['type' => 'array', 'items' => [
            'type' => 'object', 'required' => ['i', 'age', 'gender', 'locations', 'interests', 'exclusions', 'headlines', 'angle'],
            'properties' => ['i' => ['type' => 'integer', 'enum' => array_keys($pick)], 'age' => ['type' => 'string'], 'gender' => ['type' => 'string'],
                'locations' => ['type' => 'string'], 'interests' => ['type' => 'array', 'items' => ['type' => 'string']], 'exclusions' => ['type' => 'string'],
                'headlines' => ['type' => 'array', 'items' => ['type' => 'string']], 'angle' => ['type' => 'string']],
        ]]]];
        $system = "You write the creative part of new ad campaigns for a Thai shop. For EACH item (product × platform), write in Thai:\n"
            . "- age: one range like \"25-40\" based on audience_notes. gender: ทั้งหมด / หญิง / ชาย. locations: e.g. ทั่วประเทศไทย. exclusions: who to exclude (e.g. ผู้ซื้อ 30 วันล่าสุด).\n"
            . "- interests: 3 to 6 interests or search keywords that fit the product and the platform.\n"
            . "- headlines: exactly 3, each a different angle: (1) price/value — you may use price_thb, (2) the problem the product solves, (3) social proof or a seasonal reason to buy now.\n"
            . "  Google: a search headline of at most 30 characters. TikTok: a spoken hook for the first 3 seconds. Meta: a short feed headline.\n"
            . "  Never promise results or a time to results (no 'หาย', 'ภายใน 7 วัน', 'รับประกัน', '100%', 'ดีที่สุด'). The only number allowed is price_thb.\n"
            . "  The shop has NOT announced any discount, sale, free gift or free shipping: never mention one (no 'ลดราคา', 'ส่วนลด', 'แถม', 'ฟรี', 'โปร').\n"
            . "  Write the product name exactly as given, in its original spelling. Never translate it or spell it in Thai letters.\n"
            . "- angle: one sentence on the creative idea for THIS product on this platform. No numbers. Each item gets its own angle.\n"
            . "- interests must not mention any other ad platform.";
        $res = Llm::json($system, json_encode($input, JSON_UNESCAPED_UNICODE), $schema, 1800);
        $ai = [];
        foreach ($res['campaigns'] ?? [] as $r) if (isset($r['i'])) $ai[(int)$r['i']] ??= $r;

        $out = [];
        $usedAngles = [];
        foreach ($pick as $i => $c) {
            $prod = $byName[$c['product']] ?? ['name' => $c['product'], 'price' => null, 'audience' => ''];
            $ch = $c['platform'];
            $r = $ai[$i] ?? [];
            $notes = array_values(array_filter(array_map('trim', preg_split('/,/', $prod['audience'] ?? ''))));
            $age = preg_match('/^\s*(\d{2})\s*[-–]\s*(\d{2})\s*$/u', (string)($r['age'] ?? ''), $m) && $m[1] < $m[2] ? "$m[1]-$m[2]"
                : (preg_match('/(\d{2})\s*[-–]\s*(\d{2})/u', $prod['audience'] ?? '', $m) ? "$m[1]-$m[2]" : '25-44');
            // interests: no age ranges, no other platform's name ("เทรนด์ TikTok" on a Meta campaign), no duplicates
            $okInterest = fn($x) => $x !== '' && !preg_match('/\d{2}\s*[-–]\s*\d{2}/u', $x)
                && !array_filter(Diagnostics::PLATFORM_RE, fn($rx, $k) => $k !== $ch && preg_match($rx, $x), ARRAY_FILTER_USE_BOTH);
            $interests = [];
            $addInterest = function ($x) use (&$interests, $okInterest) {
                $x = trim(preg_replace('/\s+/u', ' ', (string)$x));
                if ($okInterest($x) && !in_array(mb_strtolower($x), array_map('mb_strtolower', $interests), true)) $interests[] = $x;
            };
            foreach ((array)($r['interests'] ?? []) as $x) $addInterest($x);              // the model's, if valid
            foreach ($notes as $x) if (count($interests) < 3) $addInterest($x);           // then the product's own notes
            foreach (['ช้อปปิ้งออนไลน์', 'ของใช้ส่วนตัว', 'ไลฟ์สไตล์'] as $x) if (count($interests) < 3) $addInterest($x);
            $heads = self::cleanHeadlines((array)($r['headlines'] ?? []), $prod, $ch);
            $angle = trim((string)($r['angle'] ?? ''));
            if (preg_match('/\d/', $angle) || preg_match(self::CLAIM_RE, $angle)) $angle = '';
            // a copied angle means the model attached one product's idea to another: keep it only the first time
            foreach ($usedAngles as $u) if ($angle !== '' && Diagnostics::similar($angle, $u) >= 0.7) $angle = '';
            if ($angle !== '') $usedAngles[] = $angle;
            $roas = $c['roas'];
            $fact = $roas !== null
                ? sprintf('ยังไม่ได้ยิง %s บน %s — ทั้งบัญชี %s ทำ ROAS เฉลี่ย %.2f (เป้า %.1f)%s', $prod['name'], $ch, $ch, $roas, $target,
                    $best && $best !== $ch ? sprintf('; ช่องทางที่ดีที่สุดคือ %s ROAS %.2f', $best, $plat[$best]['roas']) : '')
                : sprintf('ยังไม่ได้ยิง %s บน %s และบัญชียังไม่มีข้อมูลช่องทางนี้ — เริ่มทดสอบงบเล็ก', $prod['name'], $ch);
            $out[] = [
                'product' => $prod['name'], 'platform' => $ch,
                'campaign_name' => 'NEW_' . $ch . '_' . preg_replace('/[^\p{L}\p{N}]+/u', '', $prod['name']),
                'objective' => self::OBJECTIVE[$ch],
                'daily_budget' => (float)$per,
                'audience' => ['age' => $age, 'gender' => trim((string)($r['gender'] ?? '')) ?: 'ทั้งหมด', 'locations' => trim((string)($r['locations'] ?? '')) ?: 'ทั่วประเทศไทย',
                    'interests' => array_slice($interests, 0, 6), 'exclusions' => trim((string)($r['exclusions'] ?? '')) ?: 'ผู้ซื้อ 30 วันล่าสุด'],
                'headlines' => $heads,
                'rationale' => $fact . ($angle !== '' ? '. แนวครีเอทีฟ: ' . $angle : ''),
                'priority' => $roas === null ? 'medium' : ($roas >= $target ? 'high' : ($roas >= $breakeven ? 'medium' : 'low')),
            ];
        }
        return $out;
    }

    /** Keep the model's headlines that pass the rules; backfill with safe ones. Always exactly 3. */
    private static function cleanHeadlines(array $raw, array $prod, string $ch): array
    {
        $price = $prod['price'];
        $ok = [];
        foreach ($raw as $h) {
            $h = trim(preg_replace('/\s+/u', ' ', (string)$h), " \"'“”");
            if ($h === '' || preg_match(self::CLAIM_RE, $h) || in_array($h, $ok, true)) continue;
            if ($ch === 'Google' && mb_strlen($h) > 30) continue;
            preg_match_all('/\d[\d,]*(\.\d+)?/', $h, $m);
            if (array_filter($m[0], fn($n) => $price === null || abs((float)str_replace(',', '', $n) - $price) > 0.5)) continue;
            $ok[] = $h;
        }
        $short = $prod['name'];
        if ($ch === 'Google') { $w = preg_split('/\s+/u', $short); while (mb_strlen($short) > 18 && count($w) > 1) { array_pop($w); $short = implode(' ', $w); } }
        $fallback = [
            $price ? "$short ราคา " . number_format($price) . ' บาท' : "$short ราคาพิเศษ",
            $ch === 'TikTok' ? "ใครมีปัญหานี้อยู่ ต้องดู $short" : "ทำความรู้จัก $short",
            "ลูกค้ารีวิว $short",
        ];
        foreach ($fallback as $f) {
            if (count($ok) >= 3) break;
            if ($ch === 'Google' && mb_strlen($f) > 30) $f = mb_substr($f, 0, 30);
            if (!in_array($f, $ok, true)) $ok[] = $f;
        }
        return array_slice($ok, 0, 3);
    }

    private function ai(array $context): array
    {
        $item = [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['product', 'platform', 'campaign_name', 'objective', 'daily_budget', 'audience', 'headlines', 'rationale', 'priority'],
            'properties' => [
                'product' => ['type' => 'string'],
                'platform' => ['type' => 'string', 'enum' => self::CHANNELS],
                'campaign_name' => ['type' => 'string'],
                'objective' => ['type' => 'string'],
                'daily_budget' => ['type' => 'number'],
                'audience' => ['type' => 'object', 'additionalProperties' => false,
                    'required' => ['age', 'gender', 'locations', 'interests', 'exclusions'],
                    'properties' => [
                        'age' => ['type' => 'string'], 'gender' => ['type' => 'string'], 'locations' => ['type' => 'string'],
                        'interests' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'exclusions' => ['type' => 'string'],
                    ]],
                'headlines' => ['type' => 'array', 'items' => ['type' => 'string']],
                'rationale' => ['type' => 'string'],
                'priority' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
            ],
        ];
        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['proposals'],
            'properties' => ['proposals' => ['type' => 'array', 'items' => $item]]];
        $system = prompt_text('3_next_best_action');
        if (Llm::isLocal()) $system .= "\n\nPropose at most 3 campaigns (the best ones). Keep each rationale to one sentence.";
        $res = Llm::json($system, json_encode($context, JSON_UNESCAPED_UNICODE | (Llm::isLocal() ? 0 : JSON_PRETTY_PRINT)), $schema);
        $out = [];
        foreach (array_slice($res['proposals'], 0, 6) as $p) {
            $p['daily_budget'] = max(0, round((float)$p['daily_budget'], 0));
            $p['headlines'] = array_slice($p['headlines'], 0, 3);
            $out[] = $p;
        }
        return $out;
    }

    private static function ruleProposals(array $products, array $gaps, array $agg, ?float $avgDaily): array
    {
        $plat = $agg['platforms'];
        $bestRoas = fn($p) => $plat[$p]['roas'] ?? null;
        $budget = $avgDaily ? max(100, round($avgDaily * 0.1 / 50) * 50) : 300;
        $objective = ['Meta' => 'Sales (Advantage+ Shopping)', 'Google' => 'Sales — Search + Performance Max', 'TikTok' => 'Sales — Video Shopping Ads'];
        $out = [];
        foreach ($gaps as $g) {
            $prod = current(array_filter($products, fn($p) => $p['name'] === $g['product']));
            foreach ($g['missing'] as $ch) {
                $r = $bestRoas($ch);
                $out[] = [
                    'product' => $g['product'], 'platform' => $ch,
                    'campaign_name' => "NEW_{$ch}_" . preg_replace('/\s+/u', '', $g['product']),
                    'objective' => $objective[$ch],
                    'daily_budget' => $budget,
                    'audience' => ['age' => '25-44', 'gender' => 'ทั้งหมด', 'locations' => 'ทั่วประเทศไทย',
                        'interests' => $prod['audience'] ? array_map('trim', explode(',', $prod['audience'])) : ['Broad'],
                        'exclusions' => 'ผู้ซื้อ 30 วันล่าสุด'],
                    'headlines' => ["{$g['product']} ราคาพิเศษวันนี้", "ทำไมลูกค้ากว่า 1,000 คนเลือก {$g['product']}", "ลองก่อน ไม่พอใจคืนเงิน"],
                    'rationale' => "ยังไม่ได้ยิง {$g['product']} บน $ch" . ($r !== null ? sprintf(' — ช่องทางนี้ทำ ROAS เฉลี่ย %.2f ในบัญชี', $r) : ' — เป็นช่องทางใหม่ เริ่มทดสอบงบเล็ก'),
                    'priority' => $r !== null && $r >= 3 ? 'high' : 'medium',
                ];
            }
        }
        usort($out, fn($a, $b) => ($a['priority'] === 'high' ? 0 : 1) <=> ($b['priority'] === 'high' ? 0 : 1));
        return array_slice($out, 0, 6);
    }
}
