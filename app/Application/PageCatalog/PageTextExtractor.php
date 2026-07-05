<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\PageType;
use DOMDocument;

final class PageTextExtractor
{
    public function extract(PageType $type, string $content): string
    {
        return match ($type) {
            PageType::Markdown => $this->extractMarkdownText($content),
            PageType::HtmlArtifact => $this->extractHtmlText($content),
        };
    }

    public function extractSource(PageType $type, string $content): string
    {
        $sourceText = preg_replace('/[^\pL\pN_]+/u', ' ', $content) ?? $content;

        return $this->normalizeWhitespace($sourceText);
    }

    private function extractMarkdownText(string $markdown): string
    {
        $text = preg_replace('/\[\[([^\]]+)]]/', ' $1 ', $markdown) ?? $markdown;
        // Strip HTML tags BEFORE the Markdown-punctuation pass. That pass rewrites '>'
        // (blockquote markers) to spaces, so if it ran first a literal '</data>' in the
        // body would become an unterminated '<data ' and strip_tags() would swallow the
        // rest of the document — silently truncating extracted_text at the first tag.
        $text = strip_tags($text);
        $text = preg_replace('/```[a-zA-Z0-9_-]*\s*/', ' ', $text) ?? $text;
        $text = preg_replace('/[`*_>#\-\[\]()!]/', ' ', $text) ?? $text;

        return $this->normalizeWhitespace($text);
    }

    private function extractHtmlText(string $html): string
    {
        // Strip script/style blocks and comments up front, tolerating unterminated
        // ones (the `|$` branch). This prevents a malformed or unclosed construct in
        // untrusted artifact HTML from swallowing the trailing document text once the
        // lenient parser runs (which silently truncated extracted_text), and keeps
        // script bodies out of the search index.
        $stripped = preg_replace(
            [
                '#<script\b[^>]*>.*?(?:</script\s*>|$)#is',
                '#<style\b[^>]*>.*?(?:</style\s*>|$)#is',
                '#<!--.*?(?:-->|$)#s',
            ],
            ' ',
            $html,
        );

        if (!is_string($stripped)) {
            $stripped = $html;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            // Declare UTF-8 so libxml does not fall back to ISO-8859-1 and double-encode
            // multibyte text into mojibake (café -> cafÃ©) in the search index.
            $document->loadHTML(
                '<?xml encoding="UTF-8">' . $stripped,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );

            return $this->normalizeWhitespace($document->textContent);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function normalizeWhitespace(string $text): string
    {
        $normalized = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($normalized);
    }
}
