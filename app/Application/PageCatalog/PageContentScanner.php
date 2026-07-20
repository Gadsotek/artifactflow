<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\ContentFindingSeverity;
use App\Domain\PageCatalog\PageType;

/**
 * Advisory, best-effort content scan. It surfaces credential-like and risky
 * patterns for operators and blocks the obvious ones on save, but it is
 * trivially bypassable by light obfuscation: a clean scan is NOT proof that no
 * secret was stored. Isolation, not scanning, is the security boundary.
 */
final class PageContentScanner
{
    public function scan(PageType $type, string $content): ContentSecurityScan
    {
        $scanContent = $this->scanContent($content);

        return new ContentSecurityScan(
            blockedFindings: $this->evaluate($this->blockedRules(), $scanContent, $type),
            warningFindings: $this->evaluate($this->warningRules(), $scanContent, $type),
        );
    }

    public function scanDescription(string $description): ContentSecurityScan
    {
        $scanContent = $this->scanContent($description);

        return new ContentSecurityScan(
            blockedFindings: $this->evaluate(
                [...$this->blockedRules(), ...$this->promptInjectionRules()],
                $scanContent,
                null,
            ),
            warningFindings: [],
        );
    }

    /**
     * @param list<ContentScanRule> $rules
     *
     * @return list<ContentSecurityFinding>
     */
    private function evaluate(array $rules, string $content, ?PageType $type): array
    {
        $findings = [];

        foreach ($rules as $rule) {
            if ($rule->appliesTo($type) && $rule->matches($content)) {
                $findings[] = $rule->toFinding();
            }
        }

        return $findings;
    }

    /**
     * @return list<ContentScanRule>
     */
    private function blockedRules(): array
    {
        return [
            ContentScanRule::pattern(
                ContentFindingSeverity::Block,
                'credential_assignment',
                'A credential-like assignment was found.',
                '/(?<![A-Za-z0-9_])(?:api[_-]?key|client[_-]?secret|access[_-]?token|auth[_-]?token|password)\s*[:=]\s*["\']?[A-Za-z0-9\/+_.=-]{12,}/i',
            ),
            ContentScanRule::pattern(
                ContentFindingSeverity::Block,
                'aws_access_key_id',
                'AWS access key ID pattern was found.',
                '/\bAKIA[0-9A-Z]{16}\b/',
            ),
            ContentScanRule::pattern(
                ContentFindingSeverity::Block,
                'aws_secret_access_key',
                'AWS secret access key pattern was found.',
                '/AWS_SECRET_ACCESS_KEY\s*=\s*[A-Za-z0-9\/+=]{20,}/i',
            ),
            ContentScanRule::pattern(
                ContentFindingSeverity::Block,
                'private_key',
                'Private key material was found.',
                '/-----BEGIN (?:RSA |EC |OPENSSH |PGP )?PRIVATE KEY-----/i',
            ),
            ContentScanRule::pattern(
                ContentFindingSeverity::Block,
                'jwt',
                'JWT-like token pattern was found.',
                '/\beyJ[A-Za-z0-9_-]{7,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/',
            ),
            new ContentScanRule(
                severity: ContentFindingSeverity::Block,
                code: 'github_token',
                message: 'GitHub token pattern was found.',
                matcher: static fn (string $content): bool => preg_match('/\bgh[pousr]_[A-Za-z0-9_]{30,}\b/', $content) === 1
                    || preg_match('/\bgithub_pat_[A-Za-z0-9_]{30,}\b/', $content) === 1,
            ),
        ];
    }

    /**
     * @return list<ContentScanRule>
     */
    private function warningRules(): array
    {
        return [
            ContentScanRule::pattern(ContentFindingSeverity::Warning, 'inline_script', 'Inline script tags were found.', '/<script\b/i'),
            ContentScanRule::pattern(
                ContentFindingSeverity::Warning,
                'inline_event_handler',
                'Inline event handlers were found.',
                '/\son[a-z]+\s*=/i',
                PageType::HtmlArtifact,
            ),
            new ContentScanRule(
                severity: ContentFindingSeverity::Warning,
                code: 'javascript_url',
                message: 'JavaScript URLs were found.',
                matcher: $this->containsJavascriptUrl(...),
            ),
            ContentScanRule::pattern(ContentFindingSeverity::Warning, 'document_cookie', 'References to document.cookie were found.', '/\bdocument\s*\.\s*cookie\b/i'),
            ContentScanRule::pattern(ContentFindingSeverity::Warning, 'local_storage', 'References to localStorage were found.', '/\blocalStorage\b/i'),
            ContentScanRule::pattern(ContentFindingSeverity::Warning, 'session_storage', 'References to sessionStorage were found.', '/\bsessionStorage\b/i'),
            ContentScanRule::pattern(ContentFindingSeverity::Warning, 'window_parent', 'References to window.parent were found.', '/\bwindow\s*\.\s*parent\b/i'),
            ContentScanRule::pattern(ContentFindingSeverity::Warning, 'window_top', 'References to window.top were found.', '/\bwindow\s*\.\s*top\b/i'),
            ContentScanRule::pattern(ContentFindingSeverity::Warning, 'window_opener', 'References to window.opener were found.', '/\b(?:window\s*\.\s*)?opener\b/i'),
            ContentScanRule::pattern(ContentFindingSeverity::Warning, 'external_script', 'Script tags with external sources were found.', '/<script\b[^>]*\bsrc\s*=/i'),
            ContentScanRule::pattern(ContentFindingSeverity::Warning, 'fetch', 'Network requests using fetch() were found.', '/\bfetch\s*\(/i'),
            ContentScanRule::pattern(ContentFindingSeverity::Warning, 'xml_http_request', 'XMLHttpRequest usage was found.', '/\bXMLHttpRequest\b/i'),
            ContentScanRule::pattern(ContentFindingSeverity::Warning, 'web_socket', 'WebSocket usage was found.', '/\bWebSocket\s*\(/i'),
            ContentScanRule::pattern(ContentFindingSeverity::Warning, 'eval', 'Dynamic code execution using eval() was found.', '/\beval\s*\(/i'),
            ContentScanRule::pattern(ContentFindingSeverity::Warning, 'new_function', 'Dynamic code execution using new Function() was found.', '/\bnew\s+Function\s*\(/i'),
            ContentScanRule::pattern(
                ContentFindingSeverity::Warning,
                'exec_command_insert_html',
                'Legacy HTML insertion through document.execCommand() was found.',
                '/\bdocument\s*\.\s*execCommand\s*\(\s*["\']insertHTML["\']/i',
                PageType::HtmlArtifact,
            ),
        ];
    }

    /**
     * @return list<ContentScanRule>
     */
    private function promptInjectionRules(): array
    {
        return [
            ContentScanRule::pattern(
                ContentFindingSeverity::Block,
                'prompt_injection_instruction',
                'Instruction-like prompt text was found in page metadata.',
                '/(?:^|\R)\s*(?:system|developer|assistant|tool)\s*:/i',
            ),
        ];
    }

    private function scanContent(string $content): string
    {
        $variants = [$content];
        $decoded = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($decoded !== $content) {
            $variants[] = $decoded;
        }

        foreach ([$content, $decoded] as $variant) {
            $withoutComments = (string) preg_replace('/<!--.*?-->/s', '', $variant);

            if ($withoutComments !== $variant) {
                $variants[] = $withoutComments;
            }

            $withoutTags = trim(strip_tags($withoutComments));

            if ($withoutTags !== '' && $withoutTags !== $withoutComments) {
                $variants[] = $withoutTags;
            }
        }

        return implode("\n", array_values(array_unique($variants)));
    }

    private function containsJavascriptUrl(string $content): bool
    {
        return preg_match(
            '/j[\x00-\x20\x7F]*a[\x00-\x20\x7F]*v[\x00-\x20\x7F]*a[\x00-\x20\x7F]*s[\x00-\x20\x7F]*c[\x00-\x20\x7F]*r[\x00-\x20\x7F]*i[\x00-\x20\x7F]*p[\x00-\x20\x7F]*t[\x00-\x20\x7F]*:/i',
            $content,
        ) === 1;
    }
}
