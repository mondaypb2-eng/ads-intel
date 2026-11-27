<?php
/** Deterministic metric math. Ratios are always computed from summed totals, never averaged. */
class Metrics
{
    public static function ratio(float $a, float $b, int $dp = 2, float $mult = 1): ?float
    {
        return $b > 0 ? round($a / $b * $mult, $dp) : null;
    }

    public static function withRatios(array $r): array
    {
        $r['ctr']  = self::ratio($r['clicks'], $r['impressions'], 4, 100); // %
        $r['cpc']  = self::ratio($r['spend'], $r['clicks'], 2);
        $r['cpa']  = self::ratio($r['spend'], $r['conversions'], 2);
        $r['roas'] = self::ratio($r['revenue'], $r['spend'], 4);
        return $r;
    }

    private static function sum(array $rows): array
    {
        $t = ['spend' => 0.0, 'impressions' => 0.0, 'clicks' => 0.0, 'conversions' => 0.0, 'revenue' => 0.0];
        foreach ($rows as $r) foreach ($t as $k => $_) $t[$k] += $r[$k];
        foreach ($t as $k => $v) $t[$k] = round($v, 2);
        return self::withRatios($t);
    }

    /** Group rows into campaigns, platforms and totals. */
    public static function aggregate(array $rows): array
    {
        $byCampaign = [];
        $byPlatform = [];
        $dates = [];
        foreach ($rows as $r) {
            $id = substr(md5($r['platform'] . '|' . $r['campaign_name']), 0, 8);
            $byCampaign[$id]['rows'][] = $r;
            $byCampaign[$id]['platform'] = $r['platform'];
            $byCampaign[$id]['campaign_name'] = $r['campaign_name'];
            $byPlatform[$r['platform']][] = $r;
            if ($r['date']) $dates[] = $r['date'];
        }
        $campaigns = [];
        foreach ($byCampaign as $id => $c) {
            $freq = array_values(array_filter(array_column($c['rows'], 'frequency'), fn($v) => $v !== null));
            $campaigns[$id] = ['id' => $id, 'platform' => $c['platform'], 'campaign_name' => $c['campaign_name']]
                + self::sum($c['rows'])
                + ['frequency' => $freq ? round(array_sum($freq) / count($freq), 2) : null,
                   'days' => count(array_unique(array_filter(array_column($c['rows'], 'date')))),
                   'daily' => self::daily($c['rows'])];
        }
        uasort($campaigns, fn($a, $b) => $b['spend'] <=> $a['spend']);
        $platforms = [];
        foreach ($byPlatform as $p => $rs) $platforms[$p] = ['platform' => $p] + self::sum($rs);
        uasort($platforms, fn($a, $b) => $b['spend'] <=> $a['spend']);
        sort($dates);
        return [
            'campaigns' => $campaigns,
            'platforms' => $platforms,
            'totals'    => self::sum($rows),
            'date_from' => $dates[0] ?? null,
            'date_to'   => $dates ? end($dates) : null,
            'days'      => count(array_unique($dates)),
        ];
    }

    private static function daily(array $rows): array
    {
        $d = [];
        foreach ($rows as $r) {
            if (!$r['date']) continue;
            $d[$r['date']][] = $r;
        }
        ksort($d);
        $out = [];
        foreach ($d as $date => $rs) {
            $s = self::sum($rs);
            $freq = array_values(array_filter(array_column($rs, 'frequency'), fn($v) => $v !== null));
            $out[] = ['date' => $date] + $s + ['frequency' => $freq ? array_sum($freq) / count($freq) : null];
        }
        return $out;
    }
}
