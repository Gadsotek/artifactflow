<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\PageCatalog\PageContentScanner;
use App\Domain\PageCatalog\PageType;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PageContentScannerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function blockingPatternProvider(): iterable
    {
        yield 'generic api key assignment' => ['api_key = "sk_live_1234567890abcdef"', 'credential_assignment'];
        yield 'generic password assignment' => ['password: "correct-horse-battery-staple"', 'credential_assignment'];
        yield 'generic password assignment with markup' => [
            'password: <span>correct-horse-battery-staple</span>',
            'credential_assignment',
        ];
        yield 'generic api key assignment with html comment' => [
            'api_key<!-- split -->= sk_live_1234567890abcdef',
            'credential_assignment',
        ];
        yield 'private key' => ['-----BEGIN PRIVATE KEY-----', 'private_key'];
        yield 'jwt' => ['eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.signaturevalue123', 'jwt'];
        yield 'aws access key id' => ['AKIAIOSFODNN7EXAMPLE', 'aws_access_key_id'];
        yield 'aws access key id with markup' => ['AKIA<b></b>IOSFODNN7EXAMPLE', 'aws_access_key_id'];
        yield 'aws secret access key' => ['AWS_SECRET_ACCESS_KEY=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'aws_secret_access_key'];
        yield 'github classic token' => ['ghp_1234567890abcdefghijklmnopqrstuvwxyzABCD', 'github_token'];
        yield 'github fine grained token' => ['github_pat_1234567890ABCDEFGHIJ_abcdefghijklmnopqrstuvwxyz', 'github_token'];
    }

    #[DataProvider('blockingPatternProvider')]
    public function test_secret_like_patterns_are_blocking_without_persisting_secret_values(
        string $content,
        string $expectedCode,
    ): void {
        $scan = app(PageContentScanner::class)->scan(PageType::HtmlArtifact, $content);

        $this->assertTrue($scan->hasBlockedFindings());
        $this->assertContains($expectedCode, $scan->blockedCodes());

        foreach ($scan->blockedFindings as $finding) {
            $this->assertStringNotContainsString($content, $finding->message);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function warningPatternProvider(): iterable
    {
        yield 'document cookie' => ['document.cookie', 'document_cookie'];
        yield 'local storage' => ['localStorage.setItem("x", "y")', 'local_storage'];
        yield 'session storage' => ['sessionStorage.getItem("x")', 'session_storage'];
        yield 'window parent' => ['window.parent.postMessage("x", "*")', 'window_parent'];
        yield 'window top' => ['window.top.location', 'window_top'];
        yield 'opener' => ['window.opener', 'window_opener'];
        yield 'external script' => ['<script src="https://cdn.example.test/app.js"></script>', 'external_script'];
        yield 'fetch' => ['fetch("/api")', 'fetch'];
        yield 'xml http request' => ['new XMLHttpRequest()', 'xml_http_request'];
        yield 'web socket' => ['new WebSocket("wss://example.test")', 'web_socket'];
        yield 'eval' => ['eval("alert(1)")', 'eval'];
        yield 'new function' => ['new Function("return 1")', 'new_function'];
        yield 'entity encoded javascript URL' => ['<a href="java&#x73;cript:alert(1)">open</a>', 'javascript_url'];
        yield 'control separated javascript URL' => ['<a href="java&#x09;script:alert(1)">open</a>', 'javascript_url'];
        yield 'entity encoded document cookie' => ['document&#46;cookie', 'document_cookie'];
    }

    #[DataProvider('warningPatternProvider')]
    public function test_suspicious_javascript_patterns_are_advisory_warnings(
        string $content,
        string $expectedCode,
    ): void {
        $scan = app(PageContentScanner::class)->scan(PageType::HtmlArtifact, $content);

        $this->assertTrue($scan->hasWarningFindings());
        $this->assertContains($expectedCode, $scan->warningCodes());
    }

    public function test_common_documentation_placeholders_do_not_trigger_secret_blocking(): void
    {
        $scan = app(PageContentScanner::class)->scan(
            PageType::Markdown,
            "Set API_KEY from the environment.\npassword = input.value\nTOKEN_PLACEHOLDER",
        );

        $this->assertFalse($scan->hasBlockedFindings());
    }
}
