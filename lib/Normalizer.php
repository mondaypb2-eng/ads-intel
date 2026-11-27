<?php
/**
 * Step 1 — Data Normalization.
 * Claude proposes the column mapping (prompts/1_normalize.md); PHP applies it deterministically,
 * cleans values, converts currency and computes metrics, so the output always matches the schema.
 */
class Normalizer
{
    public const FIELDS = ['date', 'campaign_name', 'spend', 'impressions', 'clicks', 'conversions', 'revenue', 'currency', 'frequency'];
    public const PLATFORMS = ['Meta', 'Google', 'TikTok', 'Other'];

    // Built-in synonyms (header lowercased with spaces/punctuation removed). Used when Claude is off
    // or leaves a field unmapped. Prefix match is allowed so "amountspentthb" matches "amountspent".
    private const SYNONYMS = [
        'campaign_name' => ['campaignname', 'campaign', 'ชื่อแคมเปญ', 'แคมเปญ', 'adgroupcampaign'],
        'spend'         => ['amountspent', 'spend', 'cost', 'totalcost', 'ค่าใช้จ่าย', 'งบที่ใช้', 'ยอดใช้จ่าย'],
        'impressions'   => ['impressions', 'impr', 'showcnt', 'impression', 'การแสดงผล', 'อิมเพรสชัน'],
        'clicks'        => ['linkclicks', 'clicks', 'clickcnt', 'click', 'คลิก', 'จำนวนคลิก'],
        'conversions'   => ['conversions', 'results', 'purchases', 'conversion', 'conv', 'complete_payment', 'completepayment', 'คอนเวอร์ชัน', 'ยอดขายชิ้น', 'ผลลัพธ์'],
        'revenue'       => ['purchaseconversionvalue', 'purchasesconversionvalue','convvalue', 'conversionvalue', 'totalpurchasevalue', 'totalcompletepaymentrate', 'revenue', 'sales', 'purchasevalue', 'รายได้', 'มูลค่ายอดขาย', 'ยอดขาย'],
        'date'          => ['date', 'day', 'reportingstarts', 'statdate', 'stattimeday', 'วันที่'],
        'currency'      => ['currency', 'currencycode', 'สกุลเงิน'],
        'frequency'     => ['frequency', 'ความถี่'],
    ];

    private array $settings;
    public array $issues = [];
    public array $mappings = []; // per file: what was mapped and by whom

    public function __construct(?array $settings = null)
    {
        $this->settings = $settings ?? app_settings();
    }

    /** @param array $files [['name'=>..., 'content'=>...], ...] @return array canonical rows */
    public function run(array $files, bool $useAi = true): array
    {
        $rows = [];
        foreach ($files as $f) {
            $raw = $this->parse($f['name'], $f['content']);
            if (!$raw) { $this->issues[] = "{$f['name']}: ไม่พบข้อมูลที่อ่านได้"; continue; }
            $headers = array_keys(array_reduce($raw, fn($c, $r) => $c + $r, []));
            [$map, $platform, $fileCurrency, $source] = $this->resolveMapping($f['name'], $headers, $raw, $useAi);
            $this->mappings[] = ['file' => $f['name'], 'platform' => $platform, 'currency' => $fileCurrency, 'source' => $source, 'map' => $map];
            if (!$map['campaign_name']) { $this->issues[] = "{$f['name']}: หาคอลัมน์ชื่อแคมเปญไม่เจอ — ข้ามไฟล์นี้"; continue; }
            foreach ($raw as $i => $r) {
                $row = $this->normalizeRow($r, $map, $platform, $fileCurrency, $f['name'], $i + 1);
                if ($row) $rows[] = $row;
            }
        }
        return $rows;
    }

    // ---------- parsing ----------
    public function parse(string $name, string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        // UTF-16 exports (Google Ads often) → UTF-8
        if (substr($content, 0, 2) === "\xFF\xFE" || substr($content, 0, 2) === "\xFE\xFF") {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-16');
        }
        $trim = ltrim($content);
        if ($trim !== '' && ($trim[0] === '[' || $trim[0] === '{')) {
            $j = json_decode($trim, true);
            if (is_array($j)) return $this->rowsFromJson($j);
        }
        return $this->rowsFromCsv($content);
    }

    private function rowsFromJson(array $j): array
    {
        // Find the first list of objects (handles {data:{list:[...]}}, {rows:[...]}, [...])
        $list = $this->findList($j);
        $out = [];
        foreach ($list as $item) {
            if (is_array($item)) $out[] = $this->flatten($item);
        }
        return $out;
    }

    private function findList($j): array
    {
        if (is_array($j) && array_is_list_compat($j) && $j && is_array($j[0])) return $j;
        if (is_array($j)) foreach ($j as $v) {
            if (is_array($v)) { $l = $this->findList($v); if ($l) return $l; }
        }
        return [];
    }

    private function flatten(array $a): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            if (is_array($v) && !array_is_list_compat($v)) {
                foreach ($this->flatten($v) as $k2 => $v2) $out[isset($out[$k2]) ? "$k.$k2" : $k2] = $v2;
            } elseif (!is_array($v)) {
                $out[(string)$k] = $v;
            }
        }
        return $out;
    }

    private function rowsFromCsv(string $content): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        if (count($lines) < 2) return [];
        // Skip title lines some exports put above the header (e.g. Google Ads report name)
        $delims = [',' => 0, ';' => 0, "\t" => 0];
        foreach ($delims as $d => $_) $delims[$d] = count(str_getcsv($lines[min(2, count($lines) - 1)], $d));
        arsort($delims);
        $delim = array_key_first($delims);
        $width = $delims[$delim];
        $start = 0;
        while ($start < count($lines) - 1 && count(str_getcsv($lines[$start], $delim)) < $width) $start++;

        $header = array_map('trim', str_getcsv($lines[$start], $delim));
        $out = [];
        for ($i = $start + 1; $i < count($lines); $i++) {
            if (trim($lines[$i]) === '') continue;
            $cells = str_getcsv($lines[$i], $delim);
            $first = mb_strtolower(trim($cells[0] ?? ''));
            if (in_array($first, ['total', 'totals', 'รวม', 'total: account'], true)) continue; // summary rows
            $row = [];
            foreach ($header as $k => $h) if ($h !== '') $row[$h] = $cells[$k] ?? null;
            $out[] = $row;
        }
        return $out;
    }

    // ---------- mapping ----------
    public static function key(string $h): string
    {
        return preg_replace('/[^\p{L}\p{M}\p{N}_()]+/u', '', mb_strtolower(trim($h)));
    }

    private function dictionaryMap(array $headers): array
    {
        $map = array_fill_keys(self::FIELDS, '');
        $keys = [];
        foreach ($headers as $h) $keys[$h] = preg_replace('/[()_]/', '', self::key($h));
        $used = [];
        foreach (self::SYNONYMS as $field => $syns) {
            foreach ([true, false] as $exact) {          // exact matches win over prefix matches
                foreach ($syns as $syn) {
                    foreach ($keys as $h => $k) {
                        if (isset($used[$h])) continue;
                        if ($exact ? $k === $syn : str_starts_with($k, $syn)) {
                            $map[$field] = $h; $used[$h] = 1; continue 4;
                        }
                    }
                }
            }
        }
        return $map;
    }

    private function guessPlatform(string $file, array $headers): string
    {
        $s = mb_strtolower($file . ' ' . implode(' ', $headers));
        if (preg_match('/tiktok|show_cnt|stat_time|complete_payment/', $s)) return 'TikTok';
        if (preg_match('/meta|facebook|\bfb|instagram|amount spent|reporting starts|link clicks/', $s)) return 'Meta';
        if (preg_match('/google|adwords|conv\. value|impr\.|search/', $s)) return 'Google';
        return 'Other';
    }

    private function currencyFromHeaders(array $headers): string
    {
        foreach ($headers as $h) {
            if (preg_match('/\b(THB|USD|EUR|SGD|JPY)\b/i', $h, $m)) return strtoupper($m[1]);
        }
        return '';
    }

    private function resolveMapping(string $file, array $headers, array $raw, bool $useAi): array
    {
        $dict = $this->dictionaryMap($headers);
        $platform = $this->guessPlatform($file, $headers);
        $currency = $this->currencyFromHeaders($headers);
        $source = 'dictionary';

        // Local model: only ask when the dictionary couldn't find every core column (saves ~25s per file
        // and avoids a small model "improving" a mapping that was already right).
        $core = ['campaign_name', 'spend', 'impressions', 'clicks', 'conversions', 'revenue'];
        $complete = !array_filter($core, fn($f) => $dict[$f] === '') && $platform !== 'Other';
        if (Llm::isLocal() && $complete) $useAi = false;

        if ($useAi && Llm::available()) {
            try {
                $ai = $this->aiMap($file, $headers, array_slice($raw, 0, Llm::isLocal() ? 3 : 5));
                foreach (self::FIELDS as $f) {
                    if (($ai['mapping'][$f] ?? '') !== '' && in_array($ai['mapping'][$f], $headers, true)) $dict[$f] = $ai['mapping'][$f];
                }
                if (in_array($ai['platform'], self::PLATFORMS, true) && $ai['platform'] !== 'Other') $platform = $ai['platform'];
                if (!$currency && preg_match('/^[A-Z]{3}$/', $ai['currency'] ?? '')) $currency = $ai['currency'];
                $source = Llm::isLocal() ? 'local' : 'claude';
            } catch (Throwable $e) {
                $this->issues[] = "$file: AI mapping ล้มเหลว ใช้พจนานุกรมแทน (" . $e->getMessage() . ')';
            }
        }
        return [$dict, $platform, $currency ?: '', $source];
    }

    private function aiMap(string $file, array $headers, array $sample): array
    {
        $enum = array_values(array_merge([''], $headers));
        $props = [];
        foreach (self::FIELDS as $f) $props[$f] = ['type' => 'string', 'enum' => $enum];
        $schema = [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['platform', 'currency', 'mapping'],
            'properties' => [
                'platform' => ['type' => 'string', 'enum' => self::PLATFORMS],
                'currency' => ['type' => 'string', 'description' => 'ISO code of the money columns if the file states or implies it, else empty string'],
                'mapping'  => ['type' => 'object', 'additionalProperties' => false, 'required' => self::FIELDS, 'properties' => $props],
            ],
        ];
        $user = "File name: $file\nColumns: " . json_encode($headers, JSON_UNESCAPED_UNICODE)
              . "\nFirst rows:\n" . json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return Llm::json(prompt_text('1_normalize'), $user, $schema, 4000);
    }

    // ---------- values ----------
    /** Returns [float|null value, string currencyFromSymbol] */
    public static function parseNumber($v): array
    {
        if ($v === null || is_bool($v)) return [null, ''];
        if (is_int($v) || is_float($v)) return [is_finite((float)$v) ? (float)$v : null, ''];
        $s = trim((string)$v);
        if ($s === '' || preg_match('/^(-|—|–|n\/?a|null|none|nan|undefined|ไม่มีข้อมูล)$/iu', $s)) return [null, ''];
        $cur = '';
        if (preg_match('/\b(THB|USD|EUR|SGD|JPY)\b/i', $s, $m)) $cur = strtoupper($m[1]);
        elseif (str_contains($s, '฿')) $cur = 'THB';
        elseif (str_contains($s, '$')) $cur = 'USD';
        elseif (str_contains($s, '€')) $cur = 'EUR';
        elseif (str_contains($s, '¥')) $cur = 'JPY';
        $neg = preg_match('/^\(.*\)$/', $s) || str_contains($s, '-');
        $mult = 1;
        if (preg_match('/([\d.]+)\s*([kKmM])\b/', $s, $m)) $mult = strtolower($m[2]) === 'k' ? 1e3 : 1e6;
        // European "1.234,56" → "1234.56"
        if (preg_match('/^\D*\d{1,3}(\.\d{3})+,\d+\D*$/', $s)) $s = str_replace(['.', ','], ['', '.'], $s);
        $num = preg_replace('/[^\d.]/', '', str_replace(',', '', $s));
        if ($num === '' || !is_numeric($num)) return [null, $cur];
        $f = (float)$num * $mult * ($neg ? -1 : 1);
        return [$f, $cur];
    }

    public static function parseDate($v): ?string
    {
        if ($v === null || trim((string)$v) === '') return null;
        $s = trim((string)$v);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})#', $s, $m)) {
            [$a, $b, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
            if ($y > 2400) $y -= 543; // Buddhist year
            // Thai convention is day/month; switch only if the second part can't be a month
            [$d, $mo] = $b > 12 ? [$b, $a] : [$a, $b];
            return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
        }
        $t = strtotime($s);
        return $t ? date('Y-m-d', $t) : null;
    }

    private function normalizeRow(array $r, array $map, string $platform, string $fileCurrency, string $file, int $line): ?array
    {
        $get = fn($f) => $map[$f] !== '' ? ($r[$map[$f]] ?? null) : null;
        $name = trim((string)$get('campaign_name'));
        if ($name === '') { $this->issues[] = "$file แถว $line: ไม่มีชื่อแคมเปญ — ข้าม"; return null; }

        $rowCurrency = strtoupper(trim((string)$get('currency')));
        $out = [
            'date'          => self::parseDate($get('date')),
            'platform'      => $platform,
            'campaign_name' => mb_substr($name, 0, 200),
        ];
        $moneyCur = '';
        foreach (['spend', 'impressions', 'clicks', 'conversions', 'revenue', 'frequency'] as $f) {
            [$val, $cur] = self::parseNumber($get($f));
            if (in_array($f, ['spend', 'revenue'], true) && $cur) $moneyCur = $moneyCur ?: $cur;
            if ($val === null) {
                if ($f !== 'frequency' && $map[$f] !== '') $this->issues[] = "$file แถว $line ($name): ค่า $f ว่าง/อ่านไม่ได้ → ใช้ 0";
                $val = $f === 'frequency' ? null : 0.0;
            } elseif ($val < 0) {
                $this->issues[] = "$file แถว $line ($name): ค่า $f ติดลบ ($val) → ใช้ 0";
                $val = 0.0;
            }
            $out[$f] = $val;
        }
        // Currency priority: currency column → symbol in the cell → header/file currency → base
        $currency = preg_match('/^[A-Z]{3}$/', $rowCurrency) ? $rowCurrency : ($moneyCur ?: ($fileCurrency ?: $this->settings['base_currency']));
        $fx = $this->settings['fx'];
        if (!isset($fx[$currency])) {
            $this->issues[] = "$file แถว $line: ไม่รู้จักสกุลเงิน $currency → ถือเป็น {$this->settings['base_currency']}";
            $rate = 1;
        } else {
            $rate = $fx[$currency] / ($fx[$this->settings['base_currency']] ?? 1);
        }
        $out['currency'] = $currency;
        $out['spend'] = round($out['spend'] * $rate, 2);
        $out['revenue'] = round($out['revenue'] * $rate, 2);
        foreach (['impressions', 'clicks', 'conversions'] as $f) $out[$f] = round($out[$f], 2);
        if ($out['clicks'] > $out['impressions'] && $out['impressions'] > 0) {
            $this->issues[] = "$file แถว $line ($name): คลิกมากกว่าการแสดงผล — ตรวจสอบข้อมูลต้นทาง";
        }
        return Metrics::withRatios($out);
    }

    /** Schema check used by the pipeline and the eval. Returns list of errors (empty = valid). */
    public static function validateRow(array $r): array
    {
        $e = [];
        $req = ['date', 'platform', 'campaign_name', 'spend', 'impressions', 'clicks', 'conversions', 'revenue', 'currency', 'frequency', 'ctr', 'cpc', 'cpa', 'roas'];
        foreach ($req as $k) if (!array_key_exists($k, $r)) $e[] = "missing key $k";
        if ($e) return $e;
        if (!is_string($r['campaign_name']) || $r['campaign_name'] === '') $e[] = 'campaign_name empty';
        if (!in_array($r['platform'], self::PLATFORMS, true)) $e[] = 'platform invalid';
        if ($r['date'] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $r['date'])) $e[] = 'date format';
        foreach (['spend', 'impressions', 'clicks', 'conversions', 'revenue'] as $k) {
            if (!is_float($r[$k]) && !is_int($r[$k])) $e[] = "$k not number";
            elseif (!is_finite($r[$k]) || $r[$k] < 0) $e[] = "$k invalid";
        }
        foreach (['ctr', 'cpc', 'cpa', 'roas', 'frequency'] as $k) {
            if ($r[$k] !== null && (!is_numeric($r[$k]) || !is_finite($r[$k]) || $r[$k] < 0)) $e[] = "$k invalid";
        }
        if (!preg_match('/^[A-Z]{3}$/', $r['currency'])) $e[] = 'currency code';
        return $e;
    }
}

function array_is_list_compat(array $a): bool
{
    return $a === [] || array_keys($a) === range(0, count($a) - 1);
}
