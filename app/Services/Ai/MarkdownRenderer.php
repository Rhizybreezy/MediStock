<?php
declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Renders the model's Markdown into SAFE HTML. Everything is HTML-escaped first;
 * only a small whitelist is then re-introduced: headings, bold, italic, inline
 * code, bullet/numbered lists, tables and paragraphs. No links, images or raw
 * HTML are ever produced from model text.
 */
final class MarkdownRenderer
{
    public static function render(string $md): string
    {
        $lines = preg_split('/\R/', str_replace("\t", '    ', $md)) ?: [];
        $html = '';
        $list = null;
        $table = [];
        $para = [];
        $flushPara = function () use (&$para, &$html) {
            if ($para) {
                $html .= '<p>' . implode('<br>', array_map([self::class, 'inline'], $para)) . '</p>';
                $para = [];
            }
        };
        $flushList = function () use (&$list, &$html) {
            if ($list) {
                $html .= "<{$list['tag']}>" . implode('', array_map(fn ($i) => '<li>' . self::inline($i) . '</li>', $list['items'])) . "</{$list['tag']}>";
                $list = null;
            }
        };
        $flushTable = function () use (&$table, &$html) {
            if (count($table) >= 2) {
                $rows = array_values(array_filter($table, fn ($r) => !preg_match('/^\|?\s*:?-{2,}/', trim($r))));
                $html .= '<div class="table-responsive"><table class="table table-sm"><thead><tr>';
                foreach (self::cells(array_shift($rows)) as $c) {
                    $html .= '<th>' . self::inline($c) . '</th>';
                }
                $html .= '</tr></thead><tbody>';
                foreach ($rows as $r) {
                    $html .= '<tr>' . implode('', array_map(fn ($c) => '<td>' . self::inline($c) . '</td>', self::cells($r))) . '</tr>';
                }
                $html .= '</tbody></table></div>';
            } elseif ($table) {
                $html .= '<p>' . self::inline($table[0]) . '</p>';
            }
            $table = [];
        };
        foreach ($lines as $line) {
            $t = trim($line);
            if (str_starts_with($t, '|')) {
                $flushPara();
                $flushList();
                $table[] = $t;
                continue;
            }
            $flushTable();
            if ($t === '') {
                $flushPara();
                $flushList();
            } elseif (preg_match('/^(#{1,4})\s+(.*)$/', $t, $m)) {
                $flushPara();
                $flushList();
                $html .= '<h6 class="fw-bold mt-2">' . self::inline($m[2]) . '</h6>';
            } elseif (preg_match('/^[-*•]\s+(.*)$/', $t, $m) || preg_match('/^\d+[.)]\s+(.*)$/', $t, $m)) {
                $flushPara();
                $tag = preg_match('/^\d/', $t) ? 'ol' : 'ul';
                if ($list && $list['tag'] !== $tag) {
                    $flushList();
                }
                $list ??= ['tag' => $tag, 'items' => []];
                $list['items'][] = $m[1];
            } else {
                $flushList();
                $para[] = $t;
            }
        }
        $flushTable();
        $flushPara();
        $flushList();
        return $html;
    }

    private static function cells(string $row): array
    {
        return array_map('trim', explode('|', trim(trim($row), '|')));
    }

    private static function inline(string $text): string
    {
        $s = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
        $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s);
        $s = preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/s', '<em>$1</em>', $s);
        return (string) $s;
    }
}
