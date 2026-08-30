<?php
declare(strict_types=1);

namespace App\Core;

/**
 * A small, deliberately incomplete Markdown.
 *
 * The pages that describe a shelf - what it is, who runs it, the legal texts -
 * are written by their owner and shown to strangers. That combination usually
 * ends in one of two bad places: a plain textarea nobody can lay out, or a
 * rich editor that stores HTML and spends the rest of its life being patched
 * against the next way of smuggling a script through.
 *
 * This takes the third road. The input is escaped in full before a single tag
 * is produced, and every tag in the output is written here. There is no filter
 * to bypass, because no author-supplied markup ever survives to be filtered:
 * a typed <script> is text by the time this function looks at it, and stays
 * text. What the author gets in return is the handful of things prose actually
 * needs - headings, emphasis, lists, quotes, links - in a syntax that is
 * readable even when it is not rendered.
 *
 * Only http, https and mailto addresses become links. Anything else, javascript:
 * most obviously, is left as the text it was typed as.
 */
final class Markup
{
    /** Marks an already-rendered fragment while the inline passes run. */
    private const HOLD = "\x00";

    public static function render(?string $raw): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim((string) $raw));
        // NUL and friends would collide with the placeholder below, and have
        // no business in prose either way.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? '';
        if ($text === '') {
            return '';
        }

        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return self::blocks(explode("\n", $escaped));
    }

    /**
     * Walk the lines once, deciding for each what kind of block it opens or
     * continues. Nothing here looks ahead further than the current run.
     *
     * @param list<string> $lines
     */
    private static function blocks(array $lines): string
    {
        $html = '';
        $paragraph = [];
        $list = [];
        $listTag = '';
        $quote = [];

        $flushParagraph = static function () use (&$paragraph, &$html): void {
            if ($paragraph !== []) {
                $html .= '<p>' . implode('<br>', array_map(self::inline(...), $paragraph)) . '</p>';
                $paragraph = [];
            }
        };
        $flushList = static function () use (&$list, &$listTag, &$html): void {
            if ($list !== []) {
                $html .= '<' . $listTag . '>';
                foreach ($list as $item) {
                    $html .= '<li>' . self::inline($item) . '</li>';
                }
                $html .= '</' . $listTag . '>';
                $list = [];
            }
        };
        $flushQuote = static function () use (&$quote, &$html): void {
            if ($quote !== []) {
                $html .= '<blockquote><p>'
                    . implode('<br>', array_map(self::inline(...), $quote))
                    . '</p></blockquote>';
                $quote = [];
            }
        };
        $flushAll = static function () use ($flushParagraph, $flushList, $flushQuote): void {
            $flushParagraph();
            $flushList();
            $flushQuote();
        };

        foreach ($lines as $line) {
            $line = rtrim($line);

            if (trim($line) === '') {
                $flushAll();
                continue;
            }

            if (preg_match('/^\s{0,3}(-{3,}|\*{3,}|_{3,})\s*$/', $line) === 1) {
                $flushAll();
                $html .= '<hr>';
                continue;
            }

            if (preg_match('/^\s{0,3}(#{2,4})\s+(.*)$/', $line, $match) === 1) {
                $flushAll();
                // Headings start at h2: h1 is the page title, and skipping a
                // level is the one structural mistake a screen reader notices.
                $level = min(4, strlen($match[1]));
                $html .= '<h' . $level . '>' . self::inline(trim($match[2])) . '</h' . $level . '>';
                continue;
            }

            // htmlspecialchars turned "> " into "&gt; " on the way in.
            if (preg_match('/^\s{0,3}&gt;\s?(.*)$/', $line, $match) === 1) {
                $flushParagraph();
                $flushList();
                $quote[] = trim($match[1]);
                continue;
            }

            if (preg_match('/^\s{0,3}([-*])\s+(.*)$/', $line, $match) === 1) {
                $flushParagraph();
                $flushQuote();
                if ($listTag !== 'ul') {
                    $flushList();
                    $listTag = 'ul';
                }
                $list[] = trim($match[2]);
                continue;
            }

            if (preg_match('/^\s{0,3}\d+[.)]\s+(.*)$/', $line, $match) === 1) {
                $flushParagraph();
                $flushQuote();
                if ($listTag !== 'ol') {
                    $flushList();
                    $listTag = 'ol';
                }
                $list[] = trim($match[1]);
                continue;
            }

            // A line that continues a list item, indented under it.
            if ($list !== [] && preg_match('/^\s{2,}\S/', $line) === 1) {
                $list[count($list) - 1] .= ' ' . trim($line);
                continue;
            }

            $flushList();
            $flushQuote();
            $paragraph[] = trim($line);
        }

        $flushAll();

        return $html;
    }

    /**
     * Emphasis, code, and links within one line of already-escaped text.
     *
     * Links are rendered first and parked behind a placeholder, so that the
     * autolinker cannot reach inside an href and emphasis cannot chew through
     * an address that happens to contain an underscore.
     */
    private static function inline(string $escaped): string
    {
        $held = [];
        $park = static function (string $html) use (&$held): string {
            $held[] = $html;

            return self::HOLD . (count($held) - 1) . self::HOLD;
        };

        // [label](https://example.org)
        $text = preg_replace_callback(
            '~\[([^\]\n]{1,200})\]\(([^)\s]{1,500})\)~u',
            static function (array $match) use ($park): string {
                $href = self::safeHref($match[2]);
                if ($href === null) {
                    return $match[0];
                }

                return $park(self::anchor($href, self::emphasis($match[1])));
            },
            $escaped
        ) ?? $escaped;

        // A bare address, left as it was typed.
        $text = preg_replace_callback(
            '~\bhttps?://[^\s<>"\x00]+~u',
            static function (array $match) use ($park): string {
                $visible = rtrim($match[0], '.,;:)');
                $trailing = substr($match[0], strlen($visible));
                $href = self::safeHref($visible);
                if ($href === null) {
                    return $match[0];
                }

                return $park(self::anchor($href, $visible)) . $trailing;
            },
            $text
        ) ?? $text;

        $text = preg_replace_callback(
            '~`([^`\n]{1,500})`~u',
            static fn (array $match): string => $park('<code>' . $match[1] . '</code>'),
            $text
        ) ?? $text;

        $text = self::emphasis($text);

        return preg_replace_callback(
            '~' . self::HOLD . '(\d+)' . self::HOLD . '~',
            static fn (array $match): string => $held[(int) $match[1]] ?? '',
            $text
        ) ?? $text;
    }

    private static function emphasis(string $text): string
    {
        $text = preg_replace('~\*\*(?=\S)([^*\n]{1,500}?)(?<=\S)\*\*~u', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('~(?<![\w*])\*(?=\S)([^*\n]{1,500}?)(?<=\S)\*(?![\w*])~u', '<em>$1</em>', $text) ?? $text;

        return preg_replace('~(?<![\w_])_(?=\S)([^_\n]{1,500}?)(?<=\S)_(?![\w_])~u', '<em>$1</em>', $text) ?? $text;
    }

    /**
     * The address to put in an href, or null when it must not become a link.
     *
     * The value arrives HTML-escaped; it is decoded to be judged and escaped
     * again to be written, so that a & in a query string is neither
     * double-escaped nor allowed through raw.
     */
    private static function safeHref(string $escaped): ?string
    {
        $url = htmlspecialchars_decode($escaped, ENT_QUOTES);
        if (preg_match('~^(https?://[^\s]+|mailto:[^\s@]+@[^\s@]+)$~i', $url) !== 1) {
            return null;
        }

        return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function anchor(string $href, string $label): string
    {
        // Owner-written pages link outward often; rel keeps the target from
        // reaching back through window.opener.
        return '<a href="' . $href . '" rel="noopener">' . $label . '</a>';
    }
}
