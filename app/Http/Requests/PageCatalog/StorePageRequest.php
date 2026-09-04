<?php

declare(strict_types=1);

namespace App\Http\Requests\PageCatalog;

use App\Application\Administration\InstallationLimitSettings;
use App\Application\PageCatalog\PageMetadataRules;
use App\Domain\PageCatalog\PageContentEncoding;
use App\Domain\PageCatalog\PageCreationMode;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Http\Requests\AppFormRequest;
use App\Rules\StorableText;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StorePageRequest extends AppFormRequest
{
    private ?string $validatedImageContent = null;

    private ?string $validatedPdfContent = null;

    private ?string $validatedXlsxContent = null;

    private ?string $validatedDocxContent = null;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'workspace_uid' => ['required', 'string'],
            'type' => ['required', Rule::in(array_column(PageType::cases(), 'value'))],
            'mode' => ['nullable', Rule::in(array_column(PageCreationMode::cases(), 'value'))],
            'title' => ['required', 'string', new StorableText(), 'max:' . PageMetadataRules::MAX_TITLE_CHARACTERS],
            'description' => ['nullable', 'string', new StorableText(), 'max:' . PageMetadataRules::MAX_DESCRIPTION_CHARACTERS],
            'status' => ['required', Rule::in([PageStatus::Draft->value, PageStatus::Approved->value])],
            'category_uid' => ['nullable', 'string'],
            'category_name' => [
                'nullable',
                'string',
                new StorableText(),
                'max:120',
                Rule::prohibitedIf($this->filled('category_uid')),
            ],
            'parent_page_uid' => ['nullable', 'string'],
            'tags' => ['nullable', 'string', new StorableText(), 'max:1000'],
            'content' => ['nullable', 'string', 'max:' . $this->maxContentLength()],
            'html_file' => [
                'nullable',
                'file',
                'max:' . $this->htmlUploadRules()->maxUploadKilobytes($this->installationLimit('pages.max_html_bytes')),
            ],
            'image_file' => [
                'nullable',
                'file',
                'max:' . $this->imageUploadRules()->maxUploadKilobytes(),
            ],
            'pdf_file' => [
                'nullable',
                'file',
                'max:' . $this->pdfUploadRules()->maxUploadKilobytes(),
            ],
            'xlsx_file' => [
                'nullable',
                'file',
                'max:' . $this->xlsxUploadRules()->maxUploadKilobytes(),
            ],
            'docx_file' => [
                'nullable',
                'file',
                'max:' . $this->docxUploadRules()->maxUploadKilobytes(),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateTags($validator);

            $type = $this->string('type')->toString();
            $mode = $this->string('mode')->toString();

            if (!$this->modeBelongsToType($type, $mode)) {
                $validator->errors()->add('mode', 'Select a content source available for this page type.');

                return;
            }

            if ($type === PageType::Markdown->value) {
                $this->validateTextContent($validator, 'content', $this->installationLimit('pages.max_markdown_bytes'));

                return;
            }

            if ($type === PageType::Image->value) {
                if ($mode !== PageCreationMode::ImageUpload->value) {
                    $validator->errors()->add('mode', 'Image artifacts must be created by upload.');

                    return;
                }

                $this->validatedImageContent = $this->imageUploadRules()->validateUpload(
                    $validator,
                    'image_file',
                    $this->imageFile(),
                );

                return;
            }

            if ($type === PageType::Pdf->value) {
                $this->validatedPdfContent = $this->pdfUploadRules()->validateUpload(
                    $validator,
                    'pdf_file',
                    $this->pdfFile(),
                );

                return;
            }

            if ($type === PageType::Xlsx->value) {
                $this->validatedXlsxContent = $this->xlsxUploadRules()->validateUpload(
                    $validator,
                    'xlsx_file',
                    $this->xlsxFile(),
                );

                return;
            }

            if ($type === PageType::Docx->value) {
                $this->validatedDocxContent = $this->docxUploadRules()->validateUpload(
                    $validator,
                    'docx_file',
                    $this->docxFile(),
                );

                return;
            }

            if ($type !== PageType::HtmlArtifact->value) {
                return;
            }

            if ($mode === PageCreationMode::HtmlUpload->value) {
                $this->validateHtmlUpload($validator);

                return;
            }

            $this->validateTextContent($validator, 'content', $this->installationLimit('pages.max_html_bytes'));
            $this->htmlUploadRules()->validateDocumentContent($validator, 'content', $this->string('content')->toString());
        });
    }

    public function pageType(): PageType
    {
        return PageType::from($this->string('type')->toString());
    }

    public function pageStatus(): PageStatus
    {
        return PageStatus::from($this->string('status')->toString());
    }

    public function pageContent(): string
    {
        if ($this->pageType() === PageType::Image) {
            return $this->validatedImageContent ?? '';
        }

        if ($this->pageType() === PageType::Pdf) {
            return $this->validatedPdfContent ?? '';
        }

        if ($this->pageType() === PageType::Xlsx) {
            return $this->validatedXlsxContent ?? '';
        }

        if ($this->pageType() === PageType::Docx) {
            return $this->validatedDocxContent ?? '';
        }

        if ($this->pageType() === PageType::HtmlArtifact && $this->string('mode')->toString() === PageCreationMode::HtmlUpload->value) {
            $file = $this->htmlFile();

            if (!$file instanceof UploadedFile) {
                return '';
            }

            return $file->getContent();
        }

        return $this->string('content')->toString();
    }

    /**
     * @return list<string>
     */
    public function tagNames(): array
    {
        $tags = $this->string('tags')->toString();

        if (trim($tags) === '') {
            return [];
        }

        $tagNames = [];

        foreach (explode(',', $tags) as $tag) {
            $tagName = trim($tag);

            if ($tagName !== '') {
                $tagNames[] = $tagName;
            }
        }

        return $tagNames;
    }

    public function sourceFilename(): ?string
    {
        $file = match ($this->pageType()) {
            PageType::Image => $this->imageFile(),
            PageType::Pdf => $this->pdfFile(),
            PageType::Xlsx => $this->xlsxFile(),
            PageType::Docx => $this->docxFile(),
            default => $this->htmlFile(),
        };

        return $file instanceof UploadedFile ? $file->getClientOriginalName() : null;
    }

    public function pageVersionSource(): PageVersionSource
    {
        if (in_array($this->pageType(), [PageType::Image, PageType::Pdf, PageType::Xlsx, PageType::Docx], true)) {
            return PageVersionSource::Upload;
        }

        if ($this->pageType() === PageType::HtmlArtifact && $this->string('mode')->toString() === PageCreationMode::HtmlUpload->value) {
            return PageVersionSource::Upload;
        }

        return PageVersionSource::Editor;
    }

    private function validateTextContent(Validator $validator, string $field, int $maxBytes): void
    {
        $content = $this->string($field)->toString();

        if ($content === '') {
            $validator->errors()->add($field, 'Page content is required.');

            return;
        }

        if (strlen($content) > $maxBytes) {
            $validator->errors()->add($field, 'Page content exceeds the configured size limit.');
        }

        if (!PageContentEncoding::isStorable($content)) {
            $validator->errors()->add($field, 'Page content must be valid UTF-8 text without control characters.');
        }
    }

    private function modeBelongsToType(string $type, string $mode): bool
    {
        return match ($type) {
            PageType::Markdown->value => in_array($mode, ['', PageCreationMode::Markdown->value], true),
            PageType::HtmlArtifact->value => in_array(
                $mode,
                ['', PageCreationMode::HtmlPaste->value, PageCreationMode::HtmlUpload->value],
                true,
            ),
            PageType::Image->value => $mode === PageCreationMode::ImageUpload->value,
            PageType::Pdf->value => $mode === PageCreationMode::PdfUpload->value,
            PageType::Xlsx->value => $mode === PageCreationMode::XlsxUpload->value,
            PageType::Docx->value => $mode === PageCreationMode::DocxUpload->value,
            default => false,
        };
    }

    private function validateTags(Validator $validator): void
    {
        $tagNames = $this->tagNames();
        $tagLimit = $this->installationLimit('pages.max_tags_per_page');

        if (count($tagNames) > $tagLimit) {
            $validator->errors()->add('tags', sprintf('Pages can have at most %d tags.', $tagLimit));

            return;
        }

        foreach ($tagNames as $tagName) {
            if (mb_strlen($tagName) > 80) {
                $validator->errors()->add('tags', 'Tag names must be 80 characters or fewer.');

                return;
            }
        }
    }

    private function validateHtmlUpload(Validator $validator): void
    {
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

    private function imageFile(): ?UploadedFile
    {
        $file = $this->file('image_file');

        return $file instanceof UploadedFile ? $file : null;
    }

    private function pdfFile(): ?UploadedFile
    {
        $file = $this->file('pdf_file');

        return $file instanceof UploadedFile ? $file : null;
    }

    private function xlsxFile(): ?UploadedFile
    {
        $file = $this->file('xlsx_file');

        return $file instanceof UploadedFile ? $file : null;
    }

    private function docxFile(): ?UploadedFile
    {
        $file = $this->file('docx_file');

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

    private function imageUploadRules(): RasterImageUploadRules
    {
        return app(RasterImageUploadRules::class);
    }

    private function pdfUploadRules(): PdfUploadRules
    {
        return app(PdfUploadRules::class);
    }

    private function xlsxUploadRules(): XlsxUploadRules
    {
        return app(XlsxUploadRules::class);
    }

    private function docxUploadRules(): DocxUploadRules
    {
        return app(DocxUploadRules::class);
    }
}
