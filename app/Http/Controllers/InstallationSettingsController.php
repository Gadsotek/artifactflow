<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Administration\InstallationLimitPresenter;
use App\Application\Administration\InstallationUsageOverview;
use App\Application\Administration\UpdateInstallationLimits;
use App\Domain\DomainRuleViolation;
use App\Http\Requests\Administration\UpdateInstallationLimitsRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class InstallationSettingsController
{
    public function edit(
        Request $request,
        InstallationLimitPresenter $limits,
        InstallationUsageOverview $usage,
    ): View {
        $user = $request->user();

        if (!$user instanceof User || !$user->is_system_admin) {
            abort(403);
        }

        return view('admin.settings.edit', [
            'limitItems' => $limits->viewItems(),
            'limitValues' => $limits->currentValues(),
            'usage' => $usage->overview($user),
        ]);
    }

    public function update(
        UpdateInstallationLimitsRequest $request,
        UpdateInstallationLimits $updateLimits,
    ): RedirectResponse {
        $user = $request->user();

        if (!$user instanceof User || !$user->is_system_admin) {
            abort(403);
        }

        try {
            $updateLimits->handle($user, $request->values());
        } catch (DomainRuleViolation $exception) {
            throw ValidationException::withMessages([
                'realtime_enabled' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('admin.settings.edit')
            ->with('status', 'Installation limits updated.');
    }
}
