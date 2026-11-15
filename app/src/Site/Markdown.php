<?php
declare(strict_types=1);

namespace Lichtkammer\Site;

/**
 * Kleine Markdown-Teilmenge für Seitentexte: Absätze, ## und ###, **fett**, *kursiv*, Listen, Zitate, Trennlinie, Links.
 * Rohes HTML wird nie übernommen: alles wird zuerst maskiert. Links nur mit http, https, mailto oder als interne Adresse.
 */
final class Markdown
{
    public static function render(string $text): string
    {
        $lines = preg_split('/\R/', str_replace("\0", '', $text)) ?: [];
        $out = [];
        $para = [];
        $list = null; // ['ul'|'ol', items]
        $quote = [];
        $flush = static function () use (&$para, &$list, &$quote, &$out): void {
            if ($para !== []) {
                $out[] = '<p>' . self::inline(implode("\n", $para)) . '</p>';
                $para = [];
            }
            if ($list !== null) {
                $out[] = '<' . $list[0] . '>' . implode('', array_map(static fn (string $i): string => '<li>' . self::inline($i) . '</li>', $list[1])) . '</' . $list[0] . '>';
                $list = null;
            }
            if ($quote !== []) {
                $out[] = '<blockquote><p>' . self::inline(implode("\n", $quote)) . '</p></blockquote>';
                $quote = [];
            }
        };
        foreach ($lines as $line) {
            $t = rtrim($line);
            if (trim($t) === '') {
                $flush();
            } elseif (preg_match('/^(#{1,3})\s+(.+)$/', $t, $m) === 1) {
                $flush();
                $level = strlen($m[1]) === 3 ? 3 : 2;
                $out[] = "<h$level>" . self::inline($m[2]) . "</h$level>";
            } elseif (preg_match('/^(-{3,}|\*{3,})$/', trim($t)) === 1) {
                $flush();
                $out[] = '<hr>';
            } elseif (preg_match('/^[-*]\s+(.+)$/', $t, $m) === 1) {
                if ($list === null || $list[0] !== 'ul') {
                    $flush();
                    $list = ['ul', []];
                }
                $list[1][] = $m[1];
            } elseif (preg_match('/^\d+[.)]\s+(.+)$/', $t, $m) === 1) {
                if ($list === null || $list[0] !== 'ol') {
                    $flush();
                    $list = ['ol', []];
                }
                $list[1][] = $m[1];
            } elseif (preg_match('/^>\s?(.*)$/', $t, $m) === 1) {
                if ($quote === []) {
                    $flush();
                }
                $quote[] = $m[1];
            } else {
                if ($list !== null || $quote !== []) {
                    $flush();
                }
                $para[] = trim($t);
            }
        }
        $flush();
        return implode("\n", $out);
    }

    public static function inline(string $s): string
    {
        $s = htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $s = preg_replace_callback('/\[([^\]\n]+)\]\(([^)\s]+)\)/', static function (array $m): string {
            $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            if (!self::safeUrl($url)) {
                return $m[1];
            }
            $external = preg_match('#^https?://#i', $url) === 1;
            return '<a href="' . $m[2] . '"' . ($external ? ' rel="noopener noreferrer"' : '') . '>' . $m[1] . '</a>';
        }, $s) ?? $s;
        $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s) ?? $s;
        $s = preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/s', '<em>$1</em>', $s) ?? $s;
        return str_replace("\n", "<br>\n", $s);
    }

    public static function safeUrl(string $url): bool
    {
        if (preg_match('/[\x00-\x20\\\\]/', $url) === 1) {
            return false;
        }
        if (preg_match('~^(https?://[^/]|mailto:[^/]|#|/(?!/))~i', $url) === 1) {
            return true;
        }
        return false;
    }
}
