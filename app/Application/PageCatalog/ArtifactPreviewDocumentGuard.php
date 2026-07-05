<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use RuntimeException;

final class ArtifactPreviewDocumentGuard
{
    /**
     * Canonical guard body, shared with the pre-save draft preview
     * (resources/js/html-draft-preview.js imports the same file with ?raw).
     * Edit the guard logic there, never inline here.
     */
    private const string GUARD_SOURCE = 'js/artifact-preview-guard.js';

    /**
     * @var list<string>
     */
    private const array RESOURCE_HINT_RELS = ['dns-prefetch', 'preconnect', 'prefetch', 'prerender'];

    private static ?string $guardBody = null;

    /**
     * Best-effort, defense-in-depth hardening layered on top of the real boundary
     * (opaque-origin sandbox iframe + strict CSP with default-src/connect-src
     * 'none'). The regex meta-refresh / resource-hint stripping below trims
     * self-navigation and prefetch-exfil noise but is bypassable in principle and
     * must never be treated as the boundary — do not relax the sandbox or the CSP
     * on the strength of it.
     */
    public function harden(string $html, bool $recoveryEnabled = false): string
    {
        $html = $this->stripRefreshMetaTags($html);
        $html = $this->stripResourceHintLinks($html);
        $guard = $this->guardScript($recoveryEnabled);
        $withDoctypeGuard = $this->injectAfterPattern($html, '/^\s*<!doctype\s+html\b[^>]*>/i', $guard);

        return $withDoctypeGuard ?? $guard . $html;
    }

    private function guardScript(bool $recoveryEnabled): string
    {
        if (self::$guardBody === null) {
            $path = resource_path(self::GUARD_SOURCE);
            $body = is_file($path) ? file_get_contents($path) : false;

            if (!is_string($body) || trim($body) === '') {
                throw new RuntimeException('Artifact preview guard source is missing.');
            }

            self::$guardBody = $body;
        }

        $recoveryAttribute = $recoveryEnabled ? ' data-artifactflow-preview-recovery' : '';

        return "<script data-artifactflow-preview-guard{$recoveryAttribute}>\n" . self::$guardBody . "\n</script>";
    }

    private function stripRefreshMetaTags(string $html): string
    {
        $stripped = preg_replace_callback(
            '~<meta\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*+>~i',
            fn (array $matches): string => $this->isRefreshMetaTag($matches[0]) ? '' : $matches[0],
            $html,
        );

        return is_string($stripped) ? $stripped : $html;
    }

    private function stripResourceHintLinks(string $html): string
    {
        $stripped = preg_replace_callback(
            '~<link\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*+>~i',
            fn (array $matches): string => $this->isResourceHintLink($matches[0]) ? '' : $matches[0],
            $html,
        );

        return is_string($stripped) ? $stripped : $html;
    }

    private function isRefreshMetaTag(string $tag): bool
    {
        foreach ($this->tagAttributes($tag) as $name => $value) {
            if ($name === 'http-equiv' && $this->normalizeAttributeValue($value) === 'refresh') {
                return true;
            }
        }

        return false;
    }

    private function isResourceHintLink(string $tag): bool
    {
        foreach ($this->tagAttributes($tag) as $name => $value) {
            if ($name !== 'rel') {
                continue;
            }

            $rels = preg_split('/\s+/', $this->normalizeAttributeValue($value), -1, PREG_SPLIT_NO_EMPTY);

            if (!is_array($rels)) {
                return false;
            }

            foreach ($rels as $rel) {
                if (in_array($rel, self::RESOURCE_HINT_RELS, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function tagAttributes(string $tag): array
    {
        $matched = preg_match_all(
            '~([^\s"\'=<>`/]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~',
            $tag,
            $attributes,
            PREG_SET_ORDER,
        );

        if ($matched === false || $matched === 0) {
            return [];
        }

        $parsed = [];

        foreach ($attributes as $attribute) {
            $name = strtolower($attribute[1]);

            if (array_key_exists($name, $parsed)) {
                continue;
            }

            $parsed[$name] = $this->attributeValue($attribute);
        }

        return $parsed;
    }

    /**
     * @param array<int, string> $attribute
     */
    private function attributeValue(array $attribute): string
    {
        foreach ([2, 3, 4] as $index) {
            if (($attribute[$index] ?? '') !== '') {
                return $attribute[$index];
            }
        }

        return '';
    }

    private function normalizeAttributeValue(string $value): string
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
        $collapsed = preg_replace('/[\x00-\x20]+/', ' ', $decoded);

        return strtolower(trim(is_string($collapsed) ? $collapsed : $decoded));
    }

    private function injectAfterPattern(string $html, string $pattern, string $injectedHtml): ?string
    {
        $matched = preg_match($pattern, $html, $matches, PREG_OFFSET_CAPTURE);

        if ($matched !== 1) {
            return null;
        }

        $match = $matches[0][0];
        $offset = $matches[0][1] + strlen($match);

        return substr($html, 0, $offset) . $injectedHtml . substr($html, $offset);
    }
}
