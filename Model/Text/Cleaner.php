<?php
/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

declare(strict_types=1);

namespace Defox\SEOSuite\Model\Text;

/**
 * Single HTML-to-text cleaner for structured data and meta templates.
 *
 * Decodes entities FIRST, then removes markup (never the reverse: "&lt;script&gt;" must not survive as a tag).
 * Removes only real markup (comments, script/style with contents, CDATA delimiters, declarations incl. Word
 * conditionals, processing instructions, tags matching a tag-name grammar incl. namespaced "o:p", and an unclosed
 * tag at the very end), so bare "<" in text ("<3", "<10 cm", "a < b") is kept, unlike strip_tags().
 * Output is plain text, not HTML-safe: callers emitting HTML/JSON must still escape (JsonLd::render does).
 */
class Cleaner
{
    private const MARKUP = [
        '/<!--.*?(?:-->|$)/s',
        '#<(script|style)\b(?:[^<>"\']|"[^"]*"|\'[^\']*\')*+>.*?(?:</\1\s*>|$)#is',
        '/<!\[CDATA\[|\]\]>/',
        '/<!\[?[A-Za-z][^<>]*>/',
        '/<\?[A-Za-z].*?\?>/s',
        '#</?[A-Za-z][A-Za-z0-9:._-]*(?:\s(?:[^<>"\']|"[^"]*"|\'[^\']*\')*+)?/?>#',
        '#<[A-Za-z/][^<>]*$#',
    ];

    /**
     * Decode entities and remove HTML markup; whitespace and length are left to the caller.
     *
     * @param string $text
     * @return string
     */
    public function clean(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Repeat until stable so "<scr<script>ipt>" cannot reassemble into a tag after one pass.
        do {
            $previous = $text;
            $text = (string)preg_replace(self::MARKUP, '', $text);
        } while ($text !== $previous);

        return $text;
    }
}
