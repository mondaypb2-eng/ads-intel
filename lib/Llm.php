<?php
/**
 * One entry point for the pipeline's language-model calls.
 *  - provider "ollama": local model (free, offline). Settings tuned for small models: JSON-schema-constrained
 *    output, temperature 0, thinking off, compact inputs, one retry on bad JSON. HTML report design is not
 *    offered (too long and layout-sensitive for a 4B model) — the template is used.
 *  - provider "claude": Anthropic API (lib/Claude.php).
 */
class Llm
{
    public static function provider(): string { return app_settings()['provider'] === 'claude' ? 'claude' : 'ollama'; }
    public static function isLocal(): bool { return self::provider() === 'ollama'; }

    public static function label(): string
    {
        $s = app_settings();
        return self::isLocal() ? 'Local · ' . $s['ollama']['model'] : 'Claude · ' . $s['model'];
    }

    /** Why the engine can't run, or null when ready. */
    public static function problem(): ?string
    {
        $s = app_settings();
        if (!self::isLocal()) return trim($s['api_key']) === '' ? 'ยังไม่ได้ใส่ Anthropic API key' : null;
        $models = self::ollamaModels();
        if ($models === null) return 'Ollama ไม่ได้เปิดอยู่';
        $m = $s['ollama']['model'];
        if (!in_array($m, $models, true) && !in_array("$m:latest", $models, true)) return "ยังไม่มีโมเดล $m (ollama pull $m)";
        return null;
    }

    public static function available(): bool { return self::problem() === null; }

    /** Can this engine design the HTML report itself? */
    public static function canDesignHtml(): bool { return !self::isLocal() && self::available(); }

    public static function ollamaModels(): ?array
    {
        static $cache = false;
        if ($cache !== false) return $cache;
        $ch = curl_init(rtrim(app_settings()['ollama']['url'], '/') . '/api/tags');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_CONNECTTIMEOUT => 1]);
        $raw = curl_exec($ch);
        $ok = $raw !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);
        return $cache = $ok ? array_column(json_decode($raw, true)['models'] ?? [], 'name') : null;
    }

    public static function json(string $system, string $user, array $schema, int $maxTokens = 16000): array
    {
        return self::isLocal() ? self::ollamaJson($system, $user, $schema, min($maxTokens, 3000)) : Claude::json($system, $user, $schema, $maxTokens);
    }

    public static function text(string $system, string $user, int $maxTokens = 16000): string
    {
        if (self::isLocal()) return self::ollamaChat($system, $user, null, min($maxTokens, 800));
        return Claude::text($system, $user, $maxTokens);
    }

    private static function ollamaJson(string $system, string $user, array $schema, int $maxTokens): array
    {
        $system .= "\n\nAnswer with JSON only, matching the schema. Write all text values in Thai.";
        $raw = self::ollamaChat($system, $user, $schema, $maxTokens);
        $data = json_decode($raw, true);
        if (is_array($data)) return $data;
        // one retry, telling the model what went wrong
        $raw = self::ollamaChat($system, $user . "\n\n(คำตอบก่อนหน้าไม่ใช่ JSON ที่ถูกต้อง — ตอบใหม่เป็น JSON เท่านั้น)", $schema, $maxTokens);
        $data = json_decode($raw, true);
        if (!is_array($data)) throw new RuntimeException('โมเดลในเครื่องตอบไม่ใช่ JSON');
        return $data;
    }

    private static function ollamaChat(string $system, string $user, ?array $schema, int $maxTokens): string
    {
        $o = app_settings()['ollama'];
        $body = [
            'model' => $o['model'], 'stream' => false, 'think' => (bool)$o['think'], 'keep_alive' => '30m',
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
            'options' => ['temperature' => 0, 'num_ctx' => (int)$o['num_ctx'], 'num_predict' => $maxTokens],
        ];
        if ($schema) $body['format'] = $schema;
        $ch = curl_init(rtrim($o['url'], '/') . '/api/chat');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 600,
            CURLOPT_HTTPHEADER => ['content-type: application/json'], CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new RuntimeException("ต่อ Ollama ไม่ได้: $err");
        $res = json_decode($raw, true);
        if ($code !== 200) throw new RuntimeException("Ollama error ($code): " . ($res['error'] ?? substr($raw, 0, 200)));
        return (string)($res['message']['content'] ?? '');
    }
}
