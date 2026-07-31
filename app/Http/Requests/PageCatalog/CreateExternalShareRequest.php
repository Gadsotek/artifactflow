<?php

declare(strict_types=1);

namespace App\Http\Requests\PageCatalog;

use App\Domain\ExternalSharing\ExternalShareMode;
use App\Http\Requests\AppFormRequest;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

final class CreateExternalShareRequest extends AppFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $expiring = $this->input('mode') === ExternalShareMode::ExpiresAt->value;

        return [
            'mode' => [
                'required',
                'string',
                Rule::enum(ExternalShareMode::class),
            ],
            'expires_at' => [
                Rule::requiredIf($expiring),
                Rule::prohibitedIf(!$expiring),
                'date',
                'after:now',
            ],
        ];
    }

    public function mode(): ExternalShareMode
    {
        return ExternalShareMode::from($this->string('mode')->toString());
    }

    public function expiresAt(): ?CarbonImmutable
    {
        if ($this->mode() === ExternalShareMode::OneTime) {
            return null;
        }

        return CarbonImmutable::parse($this->string('expires_at')->toString())->utc();
    }
}
