<?php

declare(strict_types=1);

namespace App\Http\Requests\PageCatalog;

use App\Application\Administration\InstallationLimitSettings;
use App\Application\PageCatalog\PageMetadataRules;
use App\Http\Requests\AppFormRequest;
use App\Rules\StorableText;
use Illuminate\Validation\Validator;

final class UpdatePageMetadataRequest extends AppFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', new StorableText(), 'max:' . PageMetadataRules::MAX_TITLE_CHARACTERS],
            'description' => ['nullable', 'string', new StorableText(), 'max:' . PageMetadataRules::MAX_DESCRIPTION_CHARACTERS],
            'category_uid' => ['nullable', 'ulid'],
            'parent_page_uid' => ['nullable', 'ulid'],
            'owner_user_uid' => ['required', 'ulid'],
            'tags' => ['nullable', 'string', new StorableText(), 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $tagLimit = $this->configuredTagLimit();

            if (count($this->tagNames()) > $tagLimit) {
                $validator->errors()->add('tags', sprintf('Pages can have at most %d tags.', $tagLimit));

                return;
            }

            foreach ($this->tagNames() as $tagName) {
                if (mb_strlen($tagName) > 80) {
                    $validator->errors()->add('tags', 'Tag names must be 80 characters or fewer.');

                    return;
                }
            }
        });
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

    public function nullableString(string $field): ?string
    {
        $value = trim($this->string($field)->toString());

        return $value === '' ? null : $value;
    }

    private function configuredTagLimit(): int
    {
        return app(InstallationLimitSettings::class)->integer('pages.max_tags_per_page');
    }
}
