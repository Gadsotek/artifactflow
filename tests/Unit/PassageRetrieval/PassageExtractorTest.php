<?php

declare(strict_types=1);

namespace Tests\Unit\PassageRetrieval;

use App\Application\PassageRetrieval\PassageExtractor;
use App\Domain\PageCatalog\PageType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PassageExtractorTest extends TestCase
{
    use RefreshDatabase;

    public function test_markdown_keeps_the_rule_and_exception_with_revision_relative_coordinates(): void
    {
        $body = "Check € totals before approval.\r\n\r\n- Except for reverse-charge invoices.";
        $source = "# Billing\r\n\r\n## VAT\r\n\r\n" . $body . "\r\n\r\n## Receipt\r\n\r\nMail a receipt.";
        $result = app(PassageExtractor::class)->extract(PageType::Markdown, $source);

        self::assertSame('complete', $result->outcome->value);
        $rule = $result->passages[0];
        self::assertSame(['Billing', 'VAT'], $rule->headingPath);
        self::assertSame($body, $rule->excerpt);
        self::assertSame([
            'kind' => 'markdown_source',
            'start_line' => 5,
            'end_line' => 7,
            'start_byte' => strlen("# Billing\r\n\r\n## VAT\r\n\r\n"),
            'end_byte' => strpos($source, "\r\n\r\n## Receipt"),
        ], $rule->sourceLocator);
        self::assertSame(['Billing', 'Receipt'], $result->passages[1]->headingPath);
    }

    public function test_fenced_examples_preserve_literal_headings_and_shorter_fences(): void
    {
        $source = "Checks\n======\n\n~~~~ demo\n# Not a heading\n~~~\n## Still literal\n~~~~\n\nActual condition.";
        $result = app(PassageExtractor::class)->extract(PageType::Markdown, $source);

        self::assertNotEmpty($result->passages);
        foreach ($result->passages as $passage) {
            self::assertSame(['Checks'], $passage->headingPath);
        }
        self::assertStringContainsString('# Not a heading', implode("\n", array_column($result->passages, 'excerpt')));
        self::assertStringContainsString('## Still literal', implode("\n", array_column($result->passages, 'excerpt')));
    }

    public function test_long_multibyte_markdown_line_has_deterministic_disjoint_byte_ranges(): void
    {
        $content = str_repeat('abc€', 1900);
        $prefix = "# Evidence\n";
        $source = $prefix . $content;
        $extractor = app(PassageExtractor::class);
        $result = $extractor->extract(PageType::Markdown, $source);
        self::assertEquals($result, $extractor->extract(PageType::Markdown, $source));
        self::assertGreaterThan(1, count($result->passages));
        $cursor = strlen($prefix);
        foreach ($result->passages as $passage) {
            $locator = $passage->sourceLocator;
            self::assertSame('markdown_source', $locator['kind']);
            self::assertSame(2, $locator['start_line']);
            self::assertSame(2, $locator['end_line']);
            self::assertSame($cursor, $locator['start_byte']);
            self::assertSame($passage->excerpt, substr($source, $cursor, $locator['end_byte'] - $cursor));
            self::assertTrue(mb_check_encoding($passage->excerpt, 'UTF-8'));
            self::assertLessThanOrEqual(4096, strlen($passage->excerpt));
            $cursor = $locator['end_byte'];
        }
        self::assertSame(strlen($source), $cursor);
    }

    public function test_each_static_table_value_cites_its_own_row_and_column_in_the_original_bytes(): void
    {
        $source = '<table><tr><th>Check</th><th>On mismatch</th></tr><tr><th>Gross total (€)</th><td>Hold</td></tr>'
            . '<tr><th>VAT &amp; fee</th><td>Hold</td></tr></table>';
        $result = app(PassageExtractor::class)->extract(PageType::HtmlArtifact, $source);

        self::assertSame('complete', $result->outcome->value);
        self::assertCount(2, $result->passages);
        self::assertSame('Gross total (€) · On mismatch · Hold', $result->passages[0]->excerpt);
        self::assertSame('VAT & fee · On mismatch · Hold', $result->passages[1]->excerpt);
        self::assertSame([
            'kind' => 'html_source_ranges',
            'ranges' => [
                ['role' => 'row_header', 'start_byte' => 58, 'end_byte' => 75],
                ['role' => 'column_header', 'start_byte' => 29, 'end_byte' => 40],
                ['role' => 'body', 'start_byte' => 84, 'end_byte' => 88],
            ],
        ], $result->passages[0]->sourceLocator);
        self::assertSame('html_source_ranges', $result->passages[1]->sourceLocator['kind']);
        $ranges = $result->passages[1]->sourceLocator['ranges'];
        self::assertSame('VAT &amp; fee', substr($source, $ranges[0]['start_byte'], $ranges[0]['end_byte'] - $ranges[0]['start_byte']));
        self::assertSame(strrpos($source, 'Hold'), $ranges[2]['start_byte']);
    }

    public function test_table_blocks_separate_words_without_splitting_inline_text_or_inventing_source_ranges(): void
    {
        $source = '<table><tr><th>Check</th><th><div>Next</div><div> step</div></th></tr>'
            . '<tr><th><p>Gross</p><p>total</p></th><td><p>Pay</p><p>only after re<b>view</b></p></td></tr></table>';
        $result = app(PassageExtractor::class)->extract(PageType::HtmlArtifact, $source);

        self::assertSame('complete', $result->outcome->value);
        self::assertCount(1, $result->passages);
        $passage = $result->passages[0];
        self::assertSame('Gross total · Next step · Pay only after review', $passage->excerpt);
        $locator = $passage->sourceLocator;
        self::assertSame('html_source_ranges', $locator['kind']);
        $pieces = [];
        foreach ($locator['ranges'] as $range) {
            $pieces[$range['role']][] = substr($source, $range['start_byte'], $range['end_byte'] - $range['start_byte']);
        }
        self::assertSame([
            'row_header' => ['Gross', 'total'],
            'column_header' => ['Next', ' step'],
            'body' => ['Pay', 'only after re', 'view'],
        ], $pieces);
    }

    public function test_html_returns_only_authored_static_evidence_and_keeps_heading_and_inline_ranges(): void
    {
        $source = '<!doctype html><html><head><title>omit-title</title></head><body><h1>Billing</h1>'
            . '<section><h2>Review</h2><p data-note="omit-attribute">Check <b>total</b> &amp; VAT.</p>'
            . '<ol><li>Keep receipt.</li></ol><p hidden>omit-hidden</p><div aria-hidden="true"><p>omit-aria</p></div>'
            . '<script>const runtime = "omit-runtime";</script><style>/* omit-style */</style>'
            . '<template><p>omit-template</p></template><!-- omit-comment --></section></body></html>';
        $result = app(PassageExtractor::class)->extract(PageType::HtmlArtifact, $source);
        $text = implode("\n", array_column($result->passages, 'excerpt'));

        self::assertSame('complete', $result->outcome->value);
        self::assertStringContainsString('Check total & VAT.', $text);
        self::assertStringContainsString('Keep receipt.', $text);
        self::assertStringNotContainsString('omit-', json_encode($result->passages, JSON_THROW_ON_ERROR));
        $passage = $result->passages[0];
        self::assertSame(['Billing', 'Review'], $passage->headingPath);
        self::assertSame('html_source_ranges', $passage->sourceLocator['kind']);
        $pieces = ['heading' => [], 'body' => []];
        foreach ($passage->sourceLocator['ranges'] as $range) {
            $pieces[$range['role']][] = substr($source, $range['start_byte'], $range['end_byte'] - $range['start_byte']);
        }
        self::assertSame(['Billing', 'Review'], $pieces['heading']);
        self::assertContains('total', $pieces['body']);
        self::assertContains(' &amp; VAT.', $pieces['body']);
    }

    public function test_ambiguous_table_cells_are_omitted_without_losing_other_evidence(): void
    {
        $source = '<p>Confirmed workflow.</p><table><tr><th>Check</th><th>Fail</th></tr>'
            . '<tr><th>Gross</th><td colspan="2">ambiguous</td></tr></table><p>Verified tail.</p>';
        $result = app(PassageExtractor::class)->extract(PageType::HtmlArtifact, $source);

        self::assertSame('limited', $result->outcome->value);
        $text = implode("\n", array_column($result->passages, 'excerpt'));
        self::assertStringContainsString('Confirmed workflow.', $text);
        self::assertStringContainsString('Verified tail.', $text);
        self::assertStringNotContainsString('ambiguous', $text);
    }

    public function test_derived_evidence_including_repeated_headings_has_independent_byte_and_passage_limits(): void
    {
        $source = str_repeat('## ' . str_repeat('€', 90) . "\n" . str_repeat('c', 2700) . "\n", 340);
        $result = app(PassageExtractor::class)->extract(PageType::Markdown, $source);
        self::assertSame('limited', $result->outcome->value);
        self::assertNotEmpty($result->passages);
        self::assertLessThan(256, count($result->passages));
        self::assertLessThanOrEqual(512 * 1024, strlen(json_encode($result->passages, JSON_THROW_ON_ERROR)));

        $result = app(PassageExtractor::class)->extract(PageType::Markdown, str_repeat("## Heading\ntext\n", 300));
        self::assertSame('limited', $result->outcome->value);
        self::assertCount(256, $result->passages);
    }

    public function test_markdown_uses_the_document_outline_for_multiline_setext_and_fences_in_a_list(): void
    {
        $source = "\xef\xbb\xbfInvoice\rreview\r======\r\r- Example\r\r  ~~~text\r  # literal heading\r  ~~~\r\rFinal condition.";
        $result = app(PassageExtractor::class)->extract(PageType::Markdown, $source);

        self::assertSame('complete', $result->outcome->value);
        foreach ($result->passages as $passage) {
            self::assertSame(['Invoice review'], $passage->headingPath);
        }
        self::assertStringContainsString('# literal heading', implode("\n", array_column($result->passages, 'excerpt')));
        self::assertStringContainsString('Final condition.', implode("\n", array_column($result->passages, 'excerpt')));
    }

    public function test_a_large_multiline_block_starts_a_new_passage_if_it_can_fit_whole(): void
    {
        $previous = str_repeat('x', 3800);
        $rule = 'Condition ' . str_repeat('r', 170) . "\nUnless " . str_repeat('e', 170);
        $source = "# Rules\n\n" . $previous . "\n\n" . $rule;
        $result = app(PassageExtractor::class)->extract(PageType::Markdown, $source);

        self::assertSame([$previous, $rule], array_column($result->passages, 'excerpt'));
        self::assertSame('markdown_source', $result->passages[1]->sourceLocator['kind']);
        self::assertSame(5, $result->passages[1]->sourceLocator['start_line']);
        self::assertSame(6, $result->passages[1]->sourceLocator['end_line']);
    }

    public function test_html_preserves_block_semantics_inside_a_link_and_can_cite_a_standalone_heading(): void
    {
        $source = '<body><a href="#details"><div><h2>Approval</h2><p>Review total.</p>'
            . '<table><tr><td>Unlabelled value</td></tr></table></div></a><h2>Escalation</h2></body>';
        $result = app(PassageExtractor::class)->extract(PageType::HtmlArtifact, $source);

        self::assertSame('limited', $result->outcome->value);
        self::assertCount(2, $result->passages);
        self::assertSame('Review total.', $result->passages[0]->excerpt);
        self::assertSame(['Approval'], $result->passages[0]->headingPath);
        self::assertSame('Escalation', $result->passages[1]->excerpt);
        self::assertSame(['Escalation'], $result->passages[1]->headingPath);
        self::assertSame('html_source_ranges', $result->passages[1]->sourceLocator['kind']);
        self::assertSame('heading', $result->passages[1]->sourceLocator['ranges'][0]['role']);
    }

    public function test_html_cuts_long_multibyte_text_and_entities_at_verifiable_source_boundaries(): void
    {
        $prefix = str_repeat('a', 4093);
        $body = $prefix . '&amp;' . str_repeat('€', 1500) . ' end.';
        $source = '<p>' . $body . '</p>';
        $result = app(PassageExtractor::class)->extract(PageType::HtmlArtifact, $source);

        self::assertSame('complete', $result->outcome->value);
        self::assertGreaterThan(1, count($result->passages));
        $decoded = '';
        foreach ($result->passages as $passage) {
            self::assertLessThanOrEqual(4096, strlen($passage->excerpt));
            self::assertSame('html_source_ranges', $passage->sourceLocator['kind']);
            $raw = '';
            foreach ($passage->sourceLocator['ranges'] as $range) {
                $raw .= substr($source, $range['start_byte'], $range['end_byte'] - $range['start_byte']);
            }
            $decoded .= html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            self::assertSame($passage->excerpt, trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        }
        self::assertSame(html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $decoded);
    }

    public function test_invalid_encoding_operator_ceilings_and_excessive_depth_cannot_publish_evidence(): void
    {
        $extractor = app(PassageExtractor::class);
        $deep = str_repeat('<section>', 72) . '<p>Too deep</p>' . str_repeat('</section>', 72);
        foreach (["\xff", "word\0word", $deep] as $source) {
            $result = $extractor->extract(PageType::HtmlArtifact, $source);
            self::assertSame('limited', $result->outcome->value);
            self::assertSame([], $result->passages);
        }

        config(['pages.max_markdown_bytes' => 32]);
        $result = $extractor->extract(PageType::Markdown, str_repeat('x', 33));
        self::assertSame('limited', $result->outcome->value);
        self::assertSame([], $result->passages);
    }

    public function test_markdown_preserves_completed_blocks_before_the_independent_source_work_limit(): void
    {
        $source = "# Verified\n\nKeep this rule.\n\n# Long appendix\n\n" . str_repeat('a', 2_100_000);
        $result = app(PassageExtractor::class)->extract(PageType::Markdown, $source);

        self::assertSame('limited', $result->outcome->value);
        self::assertSame('Keep this rule.', $result->passages[0]->excerpt);
        self::assertSame(['Verified'], $result->passages[0]->headingPath);
    }

    public function test_markdown_records_heading_only_sections_and_stops_at_the_line_limit(): void
    {
        $source = "\xef\xbb\xbf# Overview\r# Policy\r\rKeep this rule.\r\r## Appendix\r"
            . str_repeat("\r", 4096) . 'Beyond the line limit.';
        $result = app(PassageExtractor::class)->extract(PageType::Markdown, $source);

        self::assertSame('limited', $result->outcome->value);
        self::assertSame(['Overview'], $result->passages[0]->headingPath);
        self::assertSame('Keep this rule.', $result->passages[1]->excerpt);
        self::assertSame(['Policy'], $result->passages[1]->headingPath);
        self::assertCount(2, $result->passages);
        foreach ($result->passages as $passage) {
            $locator = $passage->sourceLocator;
            self::assertSame('markdown_source', $locator['kind']);
            self::assertSame($passage->excerpt, substr($source, $locator['start_byte'], $locator['end_byte'] - $locator['start_byte']));
        }
    }

    public function test_an_oversized_markdown_heading_limits_the_index_without_losing_its_body(): void
    {
        $heading = str_repeat('€', 1500);
        $result = app(PassageExtractor::class)->extract(PageType::Markdown, "# $heading\n\nKeep this rule.");

        self::assertSame('limited', $result->outcome->value);
        self::assertCount(1, $result->passages);
        self::assertSame('Keep this rule.', $result->passages[0]->excerpt);
        self::assertSame([str_repeat('€', 1365)], $result->passages[0]->headingPath);
    }

    public function test_html_uses_only_the_heading_of_the_containing_section(): void
    {
        $source = '<main><h1>Billing</h1><section><h2>Invoice</h2><p>Invoice total.</p></section>'
            . '<section><p>General billing guidance.</p></section></main>';
        $result = app(PassageExtractor::class)->extract(PageType::HtmlArtifact, $source);

        self::assertSame('complete', $result->outcome->value);
        self::assertCount(2, $result->passages);
        self::assertSame(['Billing', 'Invoice'], $result->passages[0]->headingPath);
        self::assertSame(['Billing'], $result->passages[1]->headingPath);
    }

    public function test_an_unlabelled_nonempty_table_value_marks_source_coverage_limited(): void
    {
        $source = '<table><tr><th>Rule</th><th></th></tr><tr><th>Total</th><td>Hold</td></tr></table>';
        $result = app(PassageExtractor::class)->extract(PageType::HtmlArtifact, $source);
        self::assertSame('limited', $result->outcome->value);
        self::assertSame([], $result->passages);
    }

    public function test_many_inline_spans_split_around_the_heading_ranges_without_dropping_evidence(): void
    {
        $words = array_map(static fn (int $n): string => 'word' . $n, range(1, 72));
        $source = '<h1>Rules</h1><p><b>' . implode(' </b><b>', $words) . '</b></p>';
        $result = app(PassageExtractor::class)->extract(PageType::HtmlArtifact, $source);

        self::assertSame('complete', $result->outcome->value);
        self::assertGreaterThan(1, count($result->passages));
        self::assertSame(implode(' ', $words), implode(' ', array_column($result->passages, 'excerpt')));
        foreach ($result->passages as $passage) {
            self::assertSame('html_source_ranges', $passage->sourceLocator['kind']);
            self::assertLessThanOrEqual(48, count($passage->sourceLocator['ranges']));
        }
    }

    public function test_explicitly_hidden_roots_and_foreign_content_supply_no_table_or_prose_evidence(): void
    {
        $extractor = app(PassageExtractor::class);
        foreach ([
            '<html hidden><body><p>Private root.</p></body></html>',
            '<body aria-hidden="true"><table><tr><td>Hidden amount</td></tr></table></body>',
            '<div style="DISPLAY: none !important"><h1>Hidden heading</h1></div><svg><text>Chart label</text></svg>',
        ] as $source) {
            $result = $extractor->extract(PageType::HtmlArtifact, $source);
            self::assertSame('empty', $result->outcome->value);
            self::assertSame([], $result->passages);
        }
    }

    public function test_an_element_with_excessive_attributes_is_limited_before_native_tree_building(): void
    {
        $attributes = implode(' ', array_map(static fn (int $n): string => 'data-option-' . $n, range(1, 300)));
        $result = app(PassageExtractor::class)->extract(PageType::HtmlArtifact, '<p ' . $attributes . '>Text.</p>');
        self::assertSame('limited', $result->outcome->value);
        self::assertSame([], $result->passages);
    }

    public function test_html_omits_an_unmappable_long_value_but_keeps_following_evidence(): void
    {
        // Browsers decode this named reference in text without a semicolon.
        // The source verifier cannot map it using strict entity decoding.
        $large = 'Amount ' . str_repeat('a', 3000) . ' &amp ' . str_repeat('a', 1500);
        $source = "<p>$large</p><p>Review the next invoice.</p>";
        $result = app(PassageExtractor::class)->extract(PageType::HtmlArtifact, $source);

        self::assertSame('limited', $result->outcome->value);
        self::assertSame(['Review the next invoice.'], array_column($result->passages, 'excerpt'));
    }

    public function test_html_rejects_offsets_if_marker_insertion_changes_the_document_tree(): void
    {
        // The entity is an ordinary space in the original table. The marker
        // turns it into non-whitespace text that the parser moves outside it.
        $source = '<table>&#32;<tr><th>Check</th><th>Action</th></tr>'
            . '<tr><th>Total</th><td>Hold</td></tr></table>';
        $result = app(PassageExtractor::class)->extract(PageType::HtmlArtifact, $source);

        self::assertSame('limited', $result->outcome->value);
        self::assertSame([], $result->passages);
    }

    public function test_html_omits_an_oversized_heading_while_preserving_the_body(): void
    {
        $heading = str_repeat('€', 1500);
        $source = "<h1>$heading</h1><p>Match each receipt.</p>";
        $result = app(PassageExtractor::class)->extract(PageType::HtmlArtifact, $source);

        self::assertSame('limited', $result->outcome->value);
        self::assertCount(1, $result->passages);
        self::assertSame([], $result->passages[0]->headingPath);
        self::assertSame('Match each receipt.', $result->passages[0]->excerpt);
    }

    public function test_html_allows_empty_cells_and_rejects_evidence_that_cannot_fit_with_its_labels(): void
    {
        $valid = '<table><tr><th>Rule</th><th scope="col">Action</th></tr>'
            . '<tr><th scope="row">First</th><td></td></tr>'
            . '<tr><th scope="row">Total</th><td>Hold</td></tr></table>';
        $long = '<table><tr><th>Rule</th><th>' . str_repeat('a', 2500) . '</th></tr>'
            . '<tr><th>' . str_repeat('b', 2000) . '</th><td>Pay</td></tr></table>';
        $result = app(PassageExtractor::class)->extract(PageType::HtmlArtifact, '<table></table>' . $valid . $long);

        self::assertSame('limited', $result->outcome->value);
        self::assertSame(['Total · Action · Hold'], array_column($result->passages, 'excerpt'));
    }

    public function test_non_text_types_and_documents_without_static_evidence_are_empty(): void
    {
        $extractor = app(PassageExtractor::class);
        foreach ([PageType::Pdf, PageType::Docx, PageType::Xlsx, PageType::Image] as $type) {
            $result = $extractor->extract($type, '# Private binary payload');
            self::assertSame('empty', $result->outcome->value);
            self::assertSame([], $result->passages);
        }
        self::assertSame('empty', $extractor->extract(PageType::HtmlArtifact, '<script>runtime only</script>')->outcome->value);
        self::assertSame('empty', $extractor->extract(PageType::Markdown, " \r\n\t")->outcome->value);
    }
}
