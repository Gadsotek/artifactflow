<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Project base for form requests. It supplies the authorization helpers that
 * every request was otherwise re-implementing by hand: the default authorize()
 * gate (an authenticated user), the narrowing of the framework's nullable
 * Authenticatable to our concrete User, and the system-admin check.
 *
 * Requests override authorize() where the coarse "logged in" default is wrong --
 * pre-auth flows return true (route middleware guards them), and stronger gates
 * compose the helpers below. FormRequest authorization stays a coarse boundary
 * check here; the authoritative page/workspace policies run in the application
 * services the controllers call.
 */
abstract class AppFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->authenticatedUser() !== null;
    }

    protected function authenticatedUser(): ?User
    {
        $user = $this->user();

        return $user instanceof User ? $user : null;
    }

    protected function authenticatedUserIsSystemAdmin(): bool
    {
        return $this->authenticatedUser()?->is_system_admin === true;
    }
}
