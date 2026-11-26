<?php

declare(strict_types=1);

namespace App\Application\PassageRetrieval;

/**
 * Label text tokens in a parsing copy with their original byte intervals.
 * The HTML5 tree builder decides what is text and where it belongs. The
 * mapping is accepted only if removing the labels restores the original DOM.
 */
final class HtmlSourceOffsets
{
    private const array VOID_TAGS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    private const array RAW_TAGS = [
        'script', 'style', 'title', 'textarea', 'iframe', 'noembed', 'noframes', 'noscript', 'xmp', 'plaintext',
    ];

    public function annotate(string $source, string $marker, PassageCollector $collector): ?string
    {
        $offset = str_starts_with($source, "\xef\xbb\xbf") ? 3 : 0;
        $annotated = substr($source, 0, $offset);
        $length = strlen($source);
        $stack = [];

        while ($offset < $length && $collector->advance()) {
            $hasTag = preg_match('~<[a-z!/?]~i', $source, $match, PREG_OFFSET_CAPTURE, $offset);
            $start = $hasTag === 1 ? $match[0][1] : $length;

            if ($start > $offset) {
                $text = substr($source, $offset, $start - $offset);
                if (trim($text) !== '' && !in_array('template', $stack, true)) {
                    $annotated .= $marker . $offset . ':' . $start . ':';
                }
                $annotated .= $text;
                $offset = $start;
            }
            if ($offset === $length) {
                break;
            }

            if (substr_compare($source, '<!--', $offset, 4) === 0) {
                $close = strpos($source, '-->', $offset + 4);
                $end = $close === false ? $length : $close + 3;
            } elseif (preg_match('~\G<!doctype\s+html\s*>~i', $source, $tag, 0, $offset) === 1) {
                $end = $offset + strlen($tag[0]);
            } else {
                // Quoted attributes can contain > or <. Neither attributes nor
                // declaration contents may become evidence.
                $pattern = '~\G<(/?)([a-z][a-z0-9:-]*)(?=[\s/>])(?:[^<>"\']++|"[^"]*+"|\'[^\']*+\')*+>~i';
                if (preg_match($pattern, $source, $tag, 0, $offset) !== 1 || strlen($tag[0]) > 32_768) {
                    return null;
                }
                // Count attribute separators outside quotes before DOM allocates
                // attribute nodes. Whitespace around '=' counts conservatively.
                $separators = preg_match_all('~"[^"]*+"(*SKIP)(*F)|\'[^\']*+\'(*SKIP)(*F)|[\t\n\f\r ]++~', $tag[0]);
                if ($separators === false || $separators > 128 || !$collector->advance(1 + $separators)) {
                    return null;
                }
                $name = strtolower($tag[2]);
                $end = $offset + strlen($tag[0]);

                if ($tag[1] === '/') {
                    $index = array_search($name, array_reverse($stack, true), true);
                    if ($index !== false) {
                        $stack = array_slice($stack, 0, $index);
                    }
                } elseif (in_array($name, self::RAW_TAGS, true)) {
                    $found = preg_match('~</' . $name . '[\t\n\f\r ]*>~i', $source, $closing, PREG_OFFSET_CAPTURE, $end);
                    $end = $name !== 'plaintext' && $found === 1 ? $closing[0][1] + strlen($closing[0][0]) : $length;
                } elseif (!in_array($name, self::VOID_TAGS, true)) {
                    $stack[] = $name;
                    if (count($stack) > PassageCollector::MAX_DEPTH) {
                        return null;
                    }
                }
            }

            $annotated .= substr($source, $offset, $end - $offset);
            $offset = $end;
        }

        return $offset === $length ? $annotated : null;
    }
}
