<?php

declare(strict_types=1);

namespace App\Http\Requests\PageCatalog;

use App\Http\Requests\AppFormRequest;

final class PreviewMarkdownRequest extends AppFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string'],
        ];
    }

    public function content(): string
    {
        return $this->string('content')->toString();
    }
}
