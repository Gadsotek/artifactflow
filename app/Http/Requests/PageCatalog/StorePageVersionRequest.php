<?php

declare(strict_types=1);

namespace App\Http\Requests\PageCatalog;

use App\Application\Administration\InstallationLimitSettings;
use App\Domain\PageCatalog\PageContentEncoding;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Http\Requests\AppFormRequest;
use App\Models\Page;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StorePageVersionRequest extends AppFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'mode' => ['nullable', Rule::in([
                PageVersionSource::Editor->value,
                PageVersionSource::Upload->value,
                'source',
            ])],
            'content' => ['nullable', 'string', 'max:' . $this->maxContentLength()],
            'html_file' => [
                'nullable',
                'file',
                'max:' . $this->htmlUploadRules()->maxUploadKilobytes($this->installationLimit('pages.max_html_bytes')),
            ],
            'base_version_uid' => ['nullable', 'string', 'size:26'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $page = $this->route('page');

            if (!$page instanceof Page) {
                return;
            }

            $mode = $this->normalizedMode();

            if ($page->type === PageType::Markdown && $mode !== PageVersionSource::Editor) {
                $validator->errors()->add('mode', 'Markdown pages must be edited as source text.');

                return;
            }

            if ($mode === PageVersionSource::Upload) {
                $this->validateHtmlUpload($validator, $page);

                return;
            }

            $this->validateSourceContent($validator, $page);
        });
    }

    public function pageContent(): string
    {
        if ($this->normalizedMode() === PageVersionSource::Upload) {
            $file = $this->htmlFile();

            return $file instanceof UploadedFile ? $file->getContent() : '';
        }

        return $this->string('content')->toString();
    }

    public function versionSource(): PageVersionSource
    {
        return $this->normalizedMode();
    }

    public function baseVersionUid(): ?string
    {
        $baseVersionUid = $this->string('base_version_uid')->trim()->toString();

        return $baseVersionUid === '' ? null : $baseVersionUid;
    }

    private function normalizedMode(): PageVersionSource
    {
        $mode = $this->string('mode')->toString();

        if ($mode === 'source') {
            return PageVersionSource::Editor;
        }

        return PageVersionSource::tryFrom($mode) ?? PageVersionSource::Editor;
    }

    private function validateSourceContent(Validator $validator, Page $page): void
    {
        $content = $this->string('content')->toString();

        if ($content === '' || ($page->type === PageType::Markdown && trim($content) === '')) {
            $validator->errors()->add('content', 'Page content is required.');

            return;
        }

        $limit = $page->type === PageType::Markdown
            ? $this->installationLimit('pages.max_markdown_bytes')
            : $this->installationLimit('pages.max_html_bytes');

        if (strlen($content) > $limit) {
            $validator->errors()->add('content', 'Page content exceeds the configured size limit.');
        }

        if (!PageContentEncoding::isStorable($content)) {
            $validator->errors()->add('content', 'Page content must be valid UTF-8 text without control characters.');

            return;
        }

        if ($page->type === PageType::HtmlArtifact) {
            $this->htmlUploadRules()->validateDocumentContent($validator, 'content', $content);
        }
    }

    private function validateHtmlUpload(Validator $validator, Page $page): void
    {
        if ($page->type !== PageType::HtmlArtifact) {
            $validator->errors()->add('mode', 'Only HTML artifact pages can be replaced by upload.');

            return;
        }

        $this->htmlUploadRules()->validateUpload(
            validator: $validator,
            field: 'html_file',
            file: $this->htmlFile(),
            maxBytes: $this->installationLimit('pages.max_html_bytes'),
        );
    }

    private function maxContentLength(): int
    {
        return max(
            $this->installationLimit('pages.max_html_bytes'),
            $this->installationLimit('pages.max_markdown_bytes'),
        );
    }

    private function htmlFile(): ?UploadedFile
    {
        $file = $this->file('html_file');

        return $file instanceof UploadedFile ? $file : null;
    }

    private function installationLimit(string $key): int
    {
        return app(InstallationLimitSettings::class)->integer($key);
    }

    private function htmlUploadRules(): HtmlArtifactUploadRules
    {
        return app(HtmlArtifactUploadRules::class);
    }
}
