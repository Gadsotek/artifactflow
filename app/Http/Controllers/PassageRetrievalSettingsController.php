<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Administration\UpdatePassageRetrieval;
use App\Domain\DomainRuleViolation;
use App\Http\Requests\Administration\UpdatePassageRetrievalRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

final class PassageRetrievalSettingsController
{
    public function __invoke(UpdatePassageRetrievalRequest $request, UpdatePassageRetrieval $update): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        try {
            $update->handle($actor, $request->boolean('enabled'), $request->integer('generation'));
        } catch (DomainRuleViolation $error) {
            throw ValidationException::withMessages(['generation' => $error->getMessage()]);
        }

        return redirect()->route('admin.settings.edit')->with('status', 'Passage retrieval setting saved.');
    }
}
