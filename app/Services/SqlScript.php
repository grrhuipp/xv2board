<?php

namespace App\Services;

final class SqlScript
{
    /** Split installation SQL without destroying comments or quoted semicolons. */
    public static function statements(string $sql): array
    {
        $result = []; $buffer = ''; $quote = null; $comment = null;
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $c = $sql[$i]; $next = $sql[$i + 1] ?? '';
            if ($comment === 'line') {
                if ($c === "\n") { $comment = null; $buffer .= "\n"; }
                continue;
            }
            if ($comment === 'block') {
                if ($c === '*' && $next === '/') { $comment = null; $buffer .= ' '; $i++; }
                continue;
            }
            if ($quote !== null) {
                $buffer .= $c;
                if ($c === '\\' && $i + 1 < $length) { $buffer .= $sql[++$i]; continue; }
                if ($c === $quote) {
                    if ($next === $quote) { $buffer .= $sql[++$i]; } else { $quote = null; }
                }
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') { $quote = $c; $buffer .= $c; continue; }
            if ($c === '#' || ($c === '-' && $next === '-' && (ctype_space($sql[$i + 2] ?? ' ') || $i + 2 === $length))) {
                $comment = 'line'; continue;
            }
            if ($c === '/' && $next === '*') { $comment = 'block'; $i++; continue; }
            if ($c === ';') {
                if (trim($buffer) !== '') $result[] = trim($buffer);
                $buffer = '';
            } else { $buffer .= $c; }
        }
        if ($quote !== null || $comment === 'block') throw new \RuntimeException('Unterminated SQL quote or comment.');
        if (trim($buffer) !== '') $result[] = trim($buffer);
        return $result;
    }
}
