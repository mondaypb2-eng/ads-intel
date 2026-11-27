<?php
/**
 * Step 2 — Diagnostic & Anomaly.
 * Flags and status (green/yellow/red) are computed by fixed rules so they are reproducible;
 * Claude (prompts/2_diagnose.md) only explains the cause in words, using the numbers given.
 */
class Diagnostics
{
    public array $issues = [];

    public function run(array $agg, array $settings, bool $useAi = true): array
    {
        $target = (float)$settings['target_roas'];
        $breakeven = (float)$settings['breakeven_roas'];
        $cpas = array_values(array_filter(array_column($agg['campaigns'], 'cpa'), fn($v) => $v !== null));
        sort($cpas);
        $medianCpa = $cpas ? $cpas[intdiv(count($cpas), 2)] : null;
        if ($cpas && count($cpas) % 2 === 0) $medianCpa = ($cpas[count($cpas) / 2 - 1] + $cpas[count($cpas) / 2]) / 2;

        $out = [];
        foreach ($agg['campaigns'] as $id => $c) {
            $flags = [];
            // --- CPA versus the rest of the account
            if ($c['cpa'] !== null && $medianCpa && count($cpas) >= 3) {
                $x = $c['cpa'] / $medianCpa;
                if ($x >= 2) $flags[] = ['code' => 'cpa_outlier', 'level' => 'critical', 'text' => sprintf('CPA %s สูงกว่ามัธยฐานบัญชี (%s) %.1f เท่า', self::n($c['cpa']), self::n($medianCpa), $x)];
                elseif ($x >= 1.5) $flags[] = ['code' => 'cpa_outlier', 'level' => 'warning', 'text' => sprintf('CPA %s สูงกว่ามัธยฐานบัญชี (%s) %.1f เท่า', self::n($c['cpa']), self::n($medianCpa), $x)];
            }
            // --- trends inside the period
            $d = $c['daily'];
            if (count($d) >= 4) {
                $half = intdiv(count($d), 2);
                $a = self::sumSlice(array_slice($d, 0, $half));
                $b = self::sumSlice(array_slice($d, $half));
                // CPA spike: later half CPA ≥ 40% above earlier half
                if ($a['cpa'] && $b['cpa'] && $b['cpa'] >= $a['cpa'] * 1.4) {
                    $flags[] = ['code' => 'cpa_spike', 'level' => $b['cpa'] >= $a['cpa'] * 2 ? 'critical' : 'warning',
                        'text' => sprintf('CPA ช่วงหลังพุ่งจาก %s เป็น %s (+%d%%)', self::n($a['cpa']), self::n($b['cpa']), round(($b['cpa'] / $a['cpa'] - 1) * 100))];
                } elseif ($a['conversions'] > 0 && $b['conversions'] == 0 && $b['spend'] > 0) {
                    $flags[] = ['code' => 'cpa_spike', 'level' => 'critical', 'text' => sprintf('ช่วงหลังใช้งบ %s แต่ไม่มีคอนเวอร์ชันเลย', self::n($b['spend']))];
                }
                // Creative fatigue: CTR drops ≥ 25% while ads keep being served (and frequency rises if known)
                if ($a['ctr'] && $b['ctr'] !== null && $b['ctr'] <= $a['ctr'] * 0.75 && $b['impressions'] >= $a['impressions'] * 0.5) {
                    $fa = self::avgFreq(array_slice($d, 0, $half));
                    $fb = self::avgFreq(array_slice($d, $half));
                    $freqTxt = ($fa && $fb) ? sprintf(' และความถี่เพิ่มจาก %.1f เป็น %.1f', $fa, $fb) : '';
                    if (!($fa && $fb) || $fb >= $fa) {
                        $flags[] = ['code' => 'creative_fatigue', 'level' => 'warning',
                            'text' => sprintf('CTR ลดจาก %.2f%% เป็น %.2f%% (-%d%%)%s — ครีเอทีฟเริ่มล้า', $a['ctr'], $b['ctr'], round((1 - $b['ctr'] / $a['ctr']) * 100), $freqTxt)];
                    }
                }
            }
            if (($c['frequency'] ?? 0) >= 4) $flags[] = ['code' => 'high_frequency', 'level' => 'warning', 'text' => sprintf('ความถี่เฉลี่ย %.1f ครั้ง/คน — กลุ่มเป้าหมายเห็นโฆษณาซ้ำมาก', $c['frequency'])];
            if ($c['spend'] > 0 && $c['conversions'] == 0) {
                $lvl = ($medianCpa && $c['spend'] >= $medianCpa) || !$medianCpa ? 'critical' : 'warning';
                $flags[] = ['code' => 'no_conversion', 'level' => $lvl, 'text' => sprintf('ใช้งบ %s แต่ยังไม่มีคอนเวอร์ชัน', self::n($c['spend']))];
            }
            if ($c['spend'] == 0) $flags[] = ['code' => 'no_spend', 'level' => 'info', 'text' => 'ไม่มีการใช้จ่ายในช่วงนี้'];

            // --- status
            $levels = array_column($flags, 'level');
            $roas = $c['roas'];
            if ($c['spend'] == 0) { $status = 'yellow'; $action = 'review'; }
            elseif (in_array('critical', $levels, true) || $roas === null || $roas < $breakeven) { $status = 'red'; $action = 'pause'; }
            elseif ($roas >= $target && !in_array('warning', $levels, true)) { $status = 'green'; $action = 'scale'; }
            else { $status = 'yellow'; $action = 'optimize'; }
            // A critical CPA flag on a still-profitable campaign → fix, don't kill
            if ($status === 'red' && $roas !== null && $roas >= $target) { $status = 'yellow'; $action = 'optimize'; }

            $out[$id] = [
                'id' => $id, 'status' => $status, 'action' => $action, 'flags' => $flags,
                'explanation' => self::defaultExplanation($c, $status, $flags, $target, $breakeven),
                'next_steps' => self::defaultSteps($action, $flags),
            ];
        }

        $summary = self::defaultSummary($agg, $out);
        if ($useAi && Llm::available() && $out) {
            try {
                [$summary, $out] = $this->aiExplain($agg, $out, $settings, $summary);
            } catch (Throwable $e) {
                $this->issues[] = 'Claude วิเคราะห์ไม่สำเร็จ ใช้คำอธิบายจากกฎแทน (' . $e->getMessage() . ')';
            }
        }
        return ['median_cpa' => $medianCpa, 'summary' => $summary, 'campaigns' => $out];
    }

    private function aiExplain(array $agg, array $diag, array $settings, string $summary): array
    {
        $payload = [
            'base_currency' => $settings['base_currency'],
            'target_roas' => $settings['target_roas'], 'breakeven_roas' => $settings['breakeven_roas'],
            'period' => [$agg['date_from'], $agg['date_to']],
            'totals' => self::strip($agg['totals']),
            'platforms' => array_values(array_map([self::class, 'strip'], $agg['platforms'])),
            'campaigns' => [],
        ];
        foreach ($agg['campaigns'] as $id => $c) {
            $payload['campaigns'][] = ['id' => $id, 'platform' => $c['platform'], 'campaign_name' => $c['campaign_name']]
                + self::strip($c) + ['frequency' => $c['frequency'], 'status' => $diag[$id]['status'], 'action' => $diag[$id]['action'],
                   'flags' => array_map(fn($f) => $f['level'] . ': ' . $f['text'], $diag[$id]['flags'])];
        }
        $system = prompt_text('2_diagnose');
        if (Llm::isLocal()) {
            // Local model: explain only the campaigns that need action (yellow/red, biggest spend first, max 6),
            // briefly. Green campaigns keep the rule-based explanation. Output length is what costs time locally.
            // Small models misquote numbers ("Google ROAS 5.84" when it was Meta), so locally the model writes
            // NO numbers: the factual part (with numbers) comes from the rules; the model adds the likely cause + actions.
            $payload['all_campaigns'] = $payload['campaigns'];
            $need = array_values(array_filter($payload['campaigns'], fn($c) => $c['status'] !== 'green'));
            usort($need, fn($a, $b) => $b['spend'] <=> $a['spend']);
            $payload['campaigns'] = array_slice($need, 0, 6);
            return $this->localExplain($payload, $diag, $summary);
        }
        $ids = array_column($payload['campaigns'], 'id') ?: array_keys($diag);
        $schema = [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['summary', 'campaigns'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'campaigns' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'explanation', 'next_steps'],
                    'properties' => [
                        'id' => ['type' => 'string', 'enum' => $ids],
                        'explanation' => ['type' => 'string'],
                        'next_steps' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ]],
            ],
        ];
        $flags = JSON_UNESCAPED_UNICODE | (Llm::isLocal() ? 0 : JSON_PRETTY_PRINT);
        $res = Llm::json($system, json_encode($payload, $flags), $schema);
        foreach ($res['campaigns'] as $c) {
            if (!isset($diag[$c['id']])) continue;
            if (trim($c['explanation']) !== '') $diag[$c['id']]['explanation'] = $c['explanation'];
            if ($c['next_steps']) $diag[$c['id']]['next_steps'] = array_slice($c['next_steps'], 0, 4);
            $diag[$c['id']]['ai'] = true;
        }
        return [trim($res['summary']) ?: $summary, $diag];
    }

    // ---------- Local model ----------
    // A 4B model reasons badly from raw numbers (it mixes them up between campaigns) and repeats generic advice.
    // So code turns the numbers into labelled signals (no numbers), picks the main problem, and lists the
    // platform's own levers. The model only words the cause + steps; anything off (digits, another platform's
    // features, duplicates, an empty or circular cause) is replaced by rule-based text.

    public const PLATFORM_RE = [
        'Meta' => '/\b(Meta|Facebook|Instagram|IG|Reels|Stories|Advantage\+?)\b|เฟซบุ๊ก|เฟสบุ๊ค|อินสตาแกรม/iu',
        'Google' => '/\b(Google|YouTube|PMax|Performance Max)\b|กูเกิล|ยูทูบ/iu',
        'TikTok' => '/\b(TikTok|Spark Ads?|TopView)\b|ติ๊กต็อก|ติ๊กต๊อก/iu',
    ];

    private const LEVERS = [
        'Meta' => 'Meta: new creatives (Reels/Stories/carousel), Advantage+ audience or Lookalike from buyers, exclude recent buyers, frequency cap, Meta Pixel/Conversions API check, landing-page offer.',
        'Google' => 'Google: search terms report + negative keywords, match types, bidding (target CPA / target ROAS), ad copy & extensions, landing page speed and offer, PMax asset groups.',
        'TikTok' => 'TikTok: new hook in the first 3 seconds, creator content / Spark Ads, objective = conversions or Video Shopping Ads, TikTok Pixel events check, TikTok Shop offer.',
    ];

    /** Main problem code + labelled signals (Thai, no numbers) for one campaign, relative to the account. */
    private static function signals(array $c, array $d, array $med): array
    {
        $codes = array_column($d['flags'], 'code');
        $sig = [];
        $primary = null;
        $rel = function ($v, $m, $hi, $lo) { if ($v === null || !$m) return null; return $v >= $m * 1.4 ? $hi : ($v <= $m / 1.4 ? $lo : null); };
        $cvr = $c['clicks'] > 0 ? $c['conversions'] / $c['clicks'] : null;
        $aov = $c['conversions'] > 0 ? $c['revenue'] / $c['conversions'] : null;
        $objective = preg_match('/awareness|reach|topview|engagement|video ?view|traffic/i', $c['campaign_name'])
            ? 'ชื่อแคมเปญบอกว่าวัตถุประสงค์ไม่ใช่การขาย (awareness/engagement/reach)' : null;
        if (array_intersect($codes, ['creative_fatigue', 'high_frequency'])) { $primary = 'fatigue'; $sig[] = 'ครีเอทีฟเริ่มล้า: CTR ลดลงในช่วงหลังขณะที่ความถี่ (คนเดิมเห็นซ้ำ) เพิ่มขึ้น'; }
        if (in_array('no_conversion', $codes, true)) { $primary ??= 'no_conv'; $sig[] = 'ใช้งบแล้วแต่ยังไม่มียอดซื้อเลย'; }
        if (in_array('cpa_spike', $codes, true)) { $primary ??= 'cpa'; $sig[] = 'CPA ช่วงหลังของช่วงเวลาพุ่งขึ้นมาก'; }
        if (in_array('cpa_outlier', $codes, true)) { $primary ??= 'cpa'; $sig[] = 'CPA สูงกว่าแคมเปญอื่นในบัญชีมาก'; }
        if ($objective) $sig[] = $objective;
        foreach ([[$c['ctr'], $med['ctr'], 'CTR สูงกว่าค่ากลางบัญชี (คนสนใจคลิก)', 'CTR ต่ำกว่าค่ากลางบัญชี (โฆษณาไม่ดึงดูด)'],
                  [$c['cpc'], $med['cpc'], 'ค่าคลิก (CPC) แพงกว่าค่ากลางบัญชี (การแข่งขัน/บิดสูง)', 'ค่าคลิก (CPC) ถูกกว่าค่ากลางบัญชี'],
                  [$cvr, $med['cvr'], 'อัตราการซื้อต่อคลิกสูงกว่าค่ากลาง', 'อัตราการซื้อต่อคลิกต่ำกว่าค่ากลาง (คลิกแล้วไม่ซื้อ: หน้าเว็บ/ข้อเสนอ/กลุ่มคนไม่ตรง)'],
                  [$aov, $med['aov'], 'มูลค่าต่อออเดอร์สูงกว่าค่ากลาง', 'มูลค่าต่อออเดอร์ต่ำกว่าค่ากลาง (ตะกร้าเล็ก)']] as [$v, $m, $hi, $lo])
            if ($s = $rel($v, $m, $hi, $lo)) $sig[] = $s;
        $highCpc = $c['cpc'] !== null && $med['cpc'] && $c['cpc'] >= $med['cpc'] * 1.4;
        $primary ??= match (true) {
            $cvr !== null && $med['cvr'] && $cvr <= $med['cvr'] / 1.4 => 'low_cvr',
            $highCpc => 'high_cpc',
            $aov !== null && $med['aov'] && $aov <= $med['aov'] / 1.4 => 'low_aov',
            $c['ctr'] !== null && $med['ctr'] && $c['ctr'] <= $med['ctr'] / 1.4 => 'low_ctr',
            default => 'below_target',
        };
        if ($primary === 'no_conv' && $objective) $primary = 'objective';
        if ($primary === 'cpa') $primary = $highCpc ? 'cpa_cpc' : 'cpa_cvr';
        return [$primary, $sig];
    }

    /** Rule-based cause per main problem: the fallback when the model's wording fails validation. */
    private static function ruleCause(string $primary): string
    {
        return [
            'fatigue' => 'กลุ่มเป้าหมายเห็นครีเอทีฟเดิมซ้ำจนเบื่อ คนจึงคลิกน้อยลงและต้นทุนต่อยอดขายสูงขึ้น',
            'objective' => 'แคมเปญตั้งวัตถุประสงค์เป็นการรับรู้/การมีส่วนร่วม ระบบจึงหาคนดูหรือกดไลก์ ไม่ใช่คนที่พร้อมซื้อ',
            'no_conv' => 'กลุ่มเป้าหมายอาจไม่ตรงกับคนที่พร้อมซื้อ หรือการติดตามคอนเวอร์ชันอาจไม่ทำงาน',
            'cpa_cpc' => 'ค่าคลิกแพงจากการแข่งขันประมูลสูง ทำให้ต้นทุนต่อยอดขาย (CPA) สูงตาม',
            'cpa_cvr' => 'คนคลิกเข้ามาแต่ซื้อน้อย ต้นทุนต่อยอดขาย (CPA) จึงสูง น่าจะมาจากหน้าแลนดิ้งหรือข้อเสนอที่ยังไม่โดนใจ',
            'low_cvr' => 'คลิกเข้ามาแล้วซื้อน้อยกว่าแคมเปญอื่น น่าจะมาจากหน้าแลนดิ้ง ข้อเสนอ หรือกลุ่มคนที่ยังไม่ตรง',
            'high_cpc' => 'ค่าคลิกแพงกว่าแคมเปญอื่น ยอดขายที่ได้จึงไม่คุ้มกับงบที่ใช้',
            'low_aov' => 'มูลค่าต่อออเดอร์ต่ำ ยอดขายที่ได้จึงไม่พอกับค่าโฆษณา',
            'low_ctr' => 'โฆษณาดึงดูดคนคลิกได้น้อยกว่าแคมเปญอื่น ต้นทุนต่อยอดขายจึงสูง',
            'below_target' => 'ยอดขายต่อค่าโฆษณายังไม่ถึงเป้า ต้องเพิ่มมูลค่าต่อออเดอร์หรือตัดงบส่วนที่ไม่สร้างยอดขาย',
        ][$primary];
    }

    /** Rule-based steps per (main problem, platform): fills in when the model's steps are dropped. */
    private static function ruleSteps(string $primary, string $platform): array
    {
        $p = [
            'Meta' => ['fatigue' => ['ทำครีเอทีฟใหม่ 2–3 ชิ้นแบบ Reels/คารูเซล และตั้ง frequency cap', 'ขยายเป็น Advantage+ audience หรือ Lookalike จากผู้ซื้อ เพื่อลดการเห็นซ้ำ'],
                'objective' => ['เปลี่ยนวัตถุประสงค์เป็น Sales แล้วให้ Meta Pixel ใช้ event Purchase เป็นเป้า', 'ย้ายงบไปแคมเปญ Sales บน Meta ที่ทำ ROAS ได้ดีกว่า'],
                'no_conv' => ['ตรวจ Meta Pixel/Conversions API ว่ายิง event Purchase ครบ', 'เปลี่ยนเป็น Lookalike จากผู้ซื้อแทนกลุ่ม interest กว้าง'],
                'cpa_cpc' => ['ทำครีเอทีฟที่ฮุคชัดขึ้นเพื่อดึง CTR และลดค่าคลิก', 'เปิด Advantage+ placements ให้ระบบหาตำแหน่งที่ถูกกว่า'],
                'cpa_cvr' => ['ปรับหน้าแลนดิ้งให้ข้อเสนอตรงกับโฆษณาและโหลดเร็ว', 'เพิ่มข้อเสนอกระตุ้นการซื้อ เช่น ของแถมหรือส่งฟรี'],
                'low_cvr' => ['ปรับหน้าแลนดิ้งให้ข้อเสนอตรงกับโฆษณาและโหลดเร็ว', 'ตัดกลุ่ม interest ที่คลิกแต่ไม่ซื้อออก'],
                'high_cpc' => ['ทดลองครีเอทีฟวิดีโอสั้นแบบ Reels เพื่อดึง CTR และลดค่าคลิก', 'เปิด Advantage+ placements'],
                'low_aov' => ['ทำโปรแบบเซ็ต/ซื้อคู่เพื่อเพิ่มมูลค่าต่อออเดอร์', 'รีทาร์เก็ตคนที่ใส่ตะกร้าด้วยข้อเสนอเซ็ต'],
                'low_ctr' => ['ทดสอบครีเอทีฟใหม่ที่ฮุคแรงขึ้นใน Reels/Stories', 'ปรับข้อความหลักให้ชี้ปัญหาของลูกค้าชัดเจน'],
                'below_target' => ['ย้ายงบจากชุดโฆษณาที่ ROAS ต่ำไปชุดที่ดีที่สุด', 'ทำโปรแบบเซ็ตเพื่อเพิ่มมูลค่าต่อออเดอร์']],
            'Google' => ['fatigue' => ['เขียนข้อความโฆษณา (RSA) ชุดใหม่และเพิ่ม extensions', 'ขยายคีย์เวิร์ดกลุ่มใหม่เพื่อเข้าถึงคนใหม่'],
                'objective' => ['เปลี่ยนเป้าหมายเป็น Conversions และตั้ง conversion action ฝั่งยอดซื้อ', 'ย้ายงบไปแคมเปญ Search ที่มียอดขาย'],
                'no_conv' => ['ตรวจ conversion tag/GA4 ว่าบันทึกยอดซื้อครบ', 'เปิดรายงาน search terms แล้วใส่ negative keywords ตัดคำที่ไม่ใช่คนซื้อ'],
                'cpa_cpc' => ['ใส่ negative keywords จากรายงาน search terms และหยุดคีย์เวิร์ดราคาแพงที่ไม่มียอดขาย', 'เปลี่ยนการบิดเป็น target CPA'],
                'cpa_cvr' => ['ตรวจรายงาน search terms ตัดคำค้นที่ไม่ใช่คนซื้อ', 'ปรับหน้าแลนดิ้งให้ตรงกับคำค้นและโหลดเร็วบนมือถือ'],
                'low_cvr' => ['ตรวจรายงาน search terms ตัดคำค้นที่ไม่ใช่คนซื้อออก', 'ปรับหน้าแลนดิ้งให้ตรงกับคำค้นและโหลดเร็วบนมือถือ'],
                'high_cpc' => ['หยุดคีย์เวิร์ดที่ค่าคลิกแพงแต่ไม่มียอดขาย', 'เปลี่ยนการบิดเป็น target ROAS'],
                'low_aov' => ['แยก asset group ของสินค้าราคาสูงใน PMax และให้งบมากขึ้น', 'เพิ่มข้อความโปรเซ็ตใน extensions'],
                'low_ctr' => ['เขียนพาดหัว RSA ใหม่ให้มีคำค้นหลักและข้อเสนอ', 'เพิ่ม sitelink/callout extensions'],
                'below_target' => ['เปลี่ยนการบิดเป็น target ROAS', 'ตัดคำค้นที่ไม่สร้างยอดขายด้วย negative keywords']],
            'TikTok' => ['fatigue' => ['ทำวิดีโอใหม่ที่เปลี่ยนฮุค 3 วินาทีแรก', 'ใช้ Spark Ads จากคลิปครีเอเตอร์แทนคลิปเดิม'],
                'objective' => ['เปลี่ยนวัตถุประสงค์เป็น Conversions หรือ Video Shopping Ads', 'ย้ายงบไปแคมเปญ TikTok ที่มียอดขาย'],
                'no_conv' => ['ตรวจ TikTok Pixel ว่ายิง event CompletePayment ครบ', 'เปลี่ยนกลุ่มเป้าหมายเป็นคนที่เคยมีส่วนร่วมกับร้าน'],
                'cpa_cpc' => ['ทำคลิปฮุคใหม่เพื่อเพิ่ม CTR และลดค่าคลิก', 'ใช้ Spark Ads จากครีเอเตอร์ที่มีคนดูจริง'],
                'cpa_cvr' => ['ปรับหน้าสินค้าใน TikTok Shop ให้ราคาและข้อเสนอชัดเจน', 'เพิ่มคลิปรีวิวสินค้าจริงก่อนปิดการขาย'],
                'low_cvr' => ['ปรับหน้าสินค้าใน TikTok Shop ให้ราคาและข้อเสนอชัดเจน', 'ใช้คลิปรีวิวจากครีเอเตอร์เพื่อเพิ่มความน่าเชื่อถือ'],
                'high_cpc' => ['ทำคลิปฮุคใหม่เพื่อเพิ่ม CTR และลดค่าคลิก', 'ทดลองตำแหน่งโฆษณาอัตโนมัติ'],
                'low_aov' => ['ทำโปรเซ็ตหรือบันเดิลใน TikTok Shop', 'ไลฟ์ขายพร้อมโค้ดส่วนลดเมื่อซื้อครบยอด'],
                'low_ctr' => ['ทำวิดีโอใหม่ที่เปิดด้วยปัญหาลูกค้าใน 3 วินาทีแรก', 'ใช้ Spark Ads จากคลิปครีเอเตอร์'],
                'below_target' => ['ย้ายงบไปคลิปที่มียอดขายดีที่สุด', 'ทำโปรเซ็ตใน TikTok Shop เพื่อเพิ่มมูลค่าต่อออเดอร์']],
        ];
        return $p[$platform][$primary] ?? $p['Meta'][$primary];
    }

    /** Local-model variant: cause (no numbers) + next steps per campaign; numbers and summary stay rule-based. */
    private function localExplain(array $payload, array $diag, string $summary): array
    {
        if (!$payload['campaigns']) return [$summary, $diag];
        $med = self::accountMedians($payload['all_campaigns'] ?? $payload['campaigns']);
        $ids = array_column($payload['campaigns'], 'id');
        $prim = [];
        $input = [];
        foreach ($payload['campaigns'] as $c) {
            [$p, $sig] = self::signals($c, $diag[$c['id']], $med);
            $prim[$c['id']] = $p;
            $input[] = ['id' => $c['id'], 'campaign' => $c['campaign_name'], 'platform' => $c['platform'], 'status' => $c['status'],
                'main_problem' => self::ruleCause($p), 'signals' => $sig, 'platform_levers' => self::LEVERS[$c['platform']] ?? ''];
        }
        $schema = ['type' => 'object', 'required' => ['campaigns'], 'properties' => ['campaigns' => ['type' => 'array', 'items' => [
            'type' => 'object', 'required' => ['id', 'cause', 'next_steps'],
            'properties' => ['id' => ['type' => 'string', 'enum' => $ids], 'cause' => ['type' => 'string'], 'next_steps' => ['type' => 'array', 'items' => ['type' => 'string']]],
        ]]]];
        $system = "You are a performance-marketing analyst. The system has already analysed each campaign: main_problem is the diagnosis, signals are the supporting observations.\n"
            . "Write in Thai, for EACH campaign:\n"
            . "- cause: ONE sentence explaining main_problem in this campaign's own terms, using one or two of its signals. Don't contradict main_problem. Don't just repeat the status.\n"
            . "- next_steps: exactly 2 short, concrete actions for this week that fix main_problem, using ONLY that campaign's platform_levers. Never name or use another platform's features.\n"
            . "Each campaign has a different problem, so the steps must differ between campaigns.\n"
            . "Do NOT write any digits, percentages or money amounts.";
        try {
            $res = Llm::json($system, json_encode($input, JSON_UNESCAPED_UNICODE), $schema, 1500);
        } catch (Throwable $e) {
            $this->issues[] = 'LLM ในเครื่องวิเคราะห์ไม่สำเร็จ ใช้คำอธิบายจากกฎแทน (' . $e->getMessage() . ')';
            $res = ['campaigns' => []];
        }
        $byId = [];
        foreach ($res['campaigns'] ?? [] as $c) if (isset($c['id'])) $byId[$c['id']] ??= $c;
        $used = [];
        foreach ($payload['campaigns'] as $c) {
            $id = $c['id']; $plat = $c['platform']; $p = $prim[$id];
            $ai = $byId[$id] ?? null;
            $other = fn(string $s) => (bool)array_filter(self::PLATFORM_RE, fn($rx, $k) => $k !== $plat && preg_match($rx, $s), ARRAY_FILTER_USE_BOTH);
            $cause = trim(preg_replace('/\s+/u', ' ', (string)($ai['cause'] ?? '')));
            $aiCause = $cause !== '' && !preg_match('/\d/', $cause) && !$other($cause) && mb_strlen($cause) >= 15
                && !preg_match('/อยู่ระหว่างจุดคุ้มทุน|ต้องการการปรับปรุงเพื่อเพิ่มประสิทธิภาพ/u', $cause);
            if (!$aiCause) $cause = self::ruleCause($p);
            $steps = [];
            // a step that says the same as one already kept (in this campaign) adds nothing
            $repeats = function (string $s) use (&$steps) { foreach ($steps as $x) if (self::similar($s, $x) >= 0.45) return true; return false; };
            foreach ((array)($ai['next_steps'] ?? []) as $s) {
                $s = trim(preg_replace('/\s+/u', ' ', (string)$s));
                if ($s === '' || preg_match('/\d+(\.\d+)?\s*%|฿|\d{3,}/u', $s) || $other($s) || isset($used[$s]) || $repeats($s)) continue;
                $steps[] = $s;
            }
            $aiSteps = count($steps);
            foreach (self::ruleSteps($p, $plat) as $s) if (count($steps) < 2 && !isset($used[$s]) && !$repeats($s)) $steps[] = $s;
            foreach (self::ruleSteps('below_target', $plat) as $s) if (count($steps) < 2 && !isset($used[$s]) && !$repeats($s)) $steps[] = $s;
            $steps = array_slice($steps, 0, 3);
            foreach ($steps as $s) $used[$s] = true;
            $diag[$id]['explanation'] .= ' — สาเหตุที่น่าจะเป็น: ' . $cause;
            $diag[$id]['cause'] = $cause;
            $diag[$id]['next_steps'] = $steps;
            $diag[$id]['ai'] = $aiCause || $aiSteps > 0;
        }
        return [$summary, $diag];
    }

    /** Dice similarity on character bigrams (works for Thai, which has no spaces between words). */
    public static function similar(string $a, string $b): float
    {
        $bg = function ($s) { $s = preg_replace('/[\s\p{P}]+/u', '', mb_strtolower($s)); $o = []; for ($i = 0, $n = mb_strlen($s) - 1; $i < $n; $i++) $o[] = mb_substr($s, $i, 2); return $o; };
        $x = $bg($a); $y = $bg($b);
        if (!$x || !$y) return 0.0;
        $cy = array_count_values($y); $inter = 0;
        foreach (array_count_values($x) as $g => $n) $inter += min($n, $cy[$g] ?? 0);
        return 2 * $inter / (count($x) + count($y));
    }

    private static function accountMedians(array $campaigns): array
    {
        $median = function (array $v) { $v = array_values(array_filter($v, fn($x) => $x !== null)); if (!$v) return null; sort($v); $n = count($v);
            return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2; };
        $live = array_filter($campaigns, fn($c) => $c['spend'] > 0);
        return [
            'ctr' => $median(array_column($live, 'ctr')),
            'cpc' => $median(array_column($live, 'cpc')),
            'cvr' => $median(array_map(fn($c) => $c['clicks'] > 0 ? $c['conversions'] / $c['clicks'] : null, $live)),
            'aov' => $median(array_map(fn($c) => $c['conversions'] > 0 ? $c['revenue'] / $c['conversions'] : null, $live)),
        ];
    }

    public static function strip(array $c): array
    {
        $o = [];
        foreach (['spend', 'impressions', 'clicks', 'conversions', 'revenue', 'ctr', 'cpc', 'cpa', 'roas'] as $k) $o[$k] = $c[$k] ?? null;
        return $o;
    }

    private static function sumSlice(array $days): array
    {
        $t = ['spend' => 0.0, 'impressions' => 0.0, 'clicks' => 0.0, 'conversions' => 0.0, 'revenue' => 0.0];
        foreach ($days as $d) foreach ($t as $k => $_) $t[$k] += $d[$k];
        return Metrics::withRatios($t);
    }

    private static function avgFreq(array $days): ?float
    {
        $f = array_filter(array_column($days, 'frequency'), fn($v) => $v !== null);
        return $f ? array_sum($f) / count($f) : null;
    }

    public static function n($v): string { return $v === null ? '—' : number_format($v, $v >= 100 ? 0 : 2); }

    private static function defaultExplanation(array $c, string $status, array $flags, float $target, float $breakeven): string
    {
        $roas = $c['roas'] === null ? 'ไม่มี ROAS (ไม่มีการใช้จ่าย)' : sprintf('ROAS %.2f', $c['roas']);
        $base = match ($status) {
            'green' => sprintf('%s สูงกว่าเป้า %.1f และไม่พบสัญญาณผิดปกติ', $roas, $target),
            'red' => $c['roas'] !== null && $c['roas'] < $breakeven ? sprintf('%s ต่ำกว่าจุดคุ้มทุน %.1f', $roas, $breakeven) : sprintf('%s แต่พบปัญหารุนแรง', $roas),
            default => sprintf('%s อยู่ระหว่างจุดคุ้มทุนกับเป้า หรือมีสัญญาณที่ต้องแก้', $roas),
        };
        $f = array_map(fn($x) => $x['text'], array_filter($flags, fn($x) => $x['level'] !== 'info'));
        return $base . ($f ? ' — ' . implode('; ', $f) : '');
    }

    private static function defaultSteps(string $action, array $flags): array
    {
        $codes = array_column($flags, 'code');
        $s = match ($action) {
            'scale' => ['เพิ่มงบทีละ 20–30% ทุก 3–4 วัน และเฝ้าดู CPA', 'ขยายกลุ่ม Lookalike / Broad จากผู้ซื้อเดิม'],
            'pause' => ['หยุดหรือลดงบทันที แล้วย้ายงบไปแคมเปญสถานะเขียว', 'ตรวจ tracking/pixel ก่อนตัดสินใจขั้นสุดท้าย'],
            'review' => ['ตรวจว่าแคมเปญถูกปิดหรือติดปัญหาการอนุมัติหรือไม่'],
            default => ['คงงบเดิม และทดสอบ A/B ครีเอทีฟหรือกลุ่มเป้าหมายใหม่'],
        };
        if (in_array('creative_fatigue', $codes, true) || in_array('high_frequency', $codes, true)) array_unshift($s, 'เปลี่ยนครีเอทีฟ/ฮุคใหม่ 2–3 ชิ้น และขยายกลุ่มเป้าหมายเพื่อลดความถี่');
        if (in_array('cpa_spike', $codes, true)) array_unshift($s, 'ตรวจการเปลี่ยนแปลงล่าสุด (บิด, landing page, สต็อก) ช่วงที่ CPA พุ่ง');
        return array_slice($s, 0, 3);
    }

    private static function defaultSummary(array $agg, array $diag): string
    {
        $cnt = array_count_values(array_column($diag, 'status')) + ['green' => 0, 'yellow' => 0, 'red' => 0];
        $t = $agg['totals'];
        return sprintf('ใช้งบรวม %s ได้รายได้ %s (ROAS %s) จาก %d แคมเปญ: ควรขยาย %d, ปรับปรุง %d, หยุด/ลดงบ %d',
            self::n($t['spend']), self::n($t['revenue']), $t['roas'] === null ? '—' : number_format($t['roas'], 2),
            count($diag), $cnt['green'], $cnt['yellow'], $cnt['red']);
    }
}
