<?php
/**
 * Minimal Claude Messages API client over raw HTTP (cURL).
 * Raw HTTP is used because this XAMPP install has PHP 8.0 and no Composer,
 * so the official anthropic-ai/sdk package can't be installed here.
 */
class Claude
{
    public static function available(): bool
    {
        return trim(app_settings()['api_key']) !== '';
    }

    /** Ask for JSON constrained by a JSON Schema (structured outputs). Returns decoded array. */
    public static function json(string $system, string $user, array $schema, int $maxTokens = 16000): array
    {
        $text = self::call($system, $user, $maxTokens, [
            'type'   => 'json_schema',
            'schema' => $schema,
        ]);
        $data = json_decode($text, true);
        if (!is_array($data)) throw new RuntimeException('Claude returned invalid JSON');
        return $data;
    }

    /** Plain text answer. */
    public static function text(string $system, string $user, int $maxTokens = 16000): string
    {
        return self::call($system, $user, $maxTokens, null);
    }

    private static function call(string $system, string $user, int $maxTokens, ?array $format): string
    {
        try {
            return self::request($system, $user, $maxTokens, $format, true);
        } catch (RuntimeException $e) {
            // If this account/model doesn't accept server-side fallbacks, retry once without them.
            if (stripos($e->getMessage(), 'fallback') !== false && str_contains($e->getMessage(), '(400)')) {
                return self::request($system, $user, $maxTokens, $format, false);
            }
            throw $e;
        }
    }

    private static function request(string $system, string $user, int $maxTokens, ?array $format, bool $withFallback): string
    {
        $s = app_settings();
        $outputConfig = ['effort' => $s['effort']];
        if ($format) $outputConfig['format'] = $format;

        $body = [
            'model'         => $s['model'],
            'max_tokens'    => $maxTokens,
            'system'        => $system,
            'output_config' => $outputConfig,
            'messages'      => [['role' => 'user', 'content' => $user]],
        ];
        $headers = [
            'content-type: application/json',
            'x-api-key: ' . trim($s['api_key']),
            'anthropic-version: 2026-10-21',
        ];
        if ($withFallback) {
            // On a safety-classifier decline, the API retries on a fallback model in the same call.
            $body['fallbacks'] = 'default';
            $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01';
        }

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        ]);

        $attempts = 0;
        while (true) {
            $attempts++;
            $raw = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            $retryable = $raw === false || $code == 429 || $code >= 500;
            if (!$retryable || $attempts >= 3) break;
            sleep(2 * $attempts);
        }
        curl_close($ch);

        if ($raw === false) throw new RuntimeException("Network error calling Claude: $err");
        $res = json_decode($raw, true);
        if ($code !== 200) {
            $msg = $res['error']['message'] ?? substr((string)$raw, 0, 300);
            throw new RuntimeException("Claude API error ($code): $msg");
        }
        if (($res['stop_reason'] ?? '') === 'refusal') throw new RuntimeException('Claude declined this request');
        if (($res['stop_reason'] ?? '') === 'max_tokens') throw new RuntimeException('Claude response was cut off (max_tokens)');

        $out = '';
        foreach ($res['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') $out .= $block['text'];
        }
        return $out;
    }
}
