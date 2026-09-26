<?php

namespace App\Logging;

final class LogPayloadSanitizer
{
    private const MAX_BYTES = 60000; // v2_log.data/context are MySQL TEXT (65535 bytes)

    public static function redact($value, int $depth = 0)
    {
        if ($depth >= 8) return '[TRUNCATED]';
        if (is_object($value)) return ['object' => get_class($value)];
        if (!is_array($value)) return $value;

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/pass(?:word|phrase)?|secret|token|auth(?:orization|_data)?|(?:^|_)(?:key|iv|code|otp|pin)(?:$|_)/i', $key)) {
                $result[$key] = '[REDACTED]';
            } else {
                $result[$key] = self::redact($item, $depth + 1);
            }
        }
        return $result;
    }

    public static function encode($value): string
    {
        $json = json_encode(self::redact($value), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) return '{"omitted":"invalid JSON"}';
        if (strlen($json) > self::MAX_BYTES) {
            return json_encode(['omitted' => 'log payload too large', 'bytes' => strlen($json)]);
        }
        return $json;
    }
}
