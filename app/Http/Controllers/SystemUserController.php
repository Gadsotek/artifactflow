<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Identity\CreateUser;
use App\Application\Identity\SystemUserOverview;
use App\Domain\DomainRuleViolation;
use App\Http\Requests\Identity\StoreManagedUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class SystemUserController
{
    public function index(Request $request, SystemUserOverview $overview): View
    {
        $user = $request->user();

        if (!$user instanceof User || !$user->is_system_admin) {
            abort(403);
        }

        return view('admin.users.index', [
            'artifactUrl' => $this->configString('app.artifact_url'),
            'appUrl' => $this->configString('app.url'),
            'sourceUrl' => $this->configString('app.source_url'),
            'users' => $overview->forSystemAdmin($user),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(StoreManagedUserRequest $request, CreateUser $createUser): RedirectResponse
    {
        $actor = $request->user();

        if (!$actor instanceof User) {
            abort(403);
        }

        try {
            $createUser->handle(
                name: $request->string('name')->toString(),
                email: $request->string('email')->toString(),
                password: $request->string('password')->toString(),
                actor: $actor,
            );
        } catch (DomainRuleViolation $exception) {
            throw ValidationException::withMessages([
                'email' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('admin.users.index')
            ->with('status', 'User created.');
    }

    private function configString(string $key): string
    {
        $value = config($key);

        return is_string($value) ? $value : '';
    }
}
