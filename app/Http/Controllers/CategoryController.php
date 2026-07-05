<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PageCatalog\CreateCategory;
use App\Application\PageCatalog\CreateCategoryCommand;
use App\Domain\DomainRuleViolation;
use App\Http\Requests\PageCatalog\StoreCategoryRequest;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

final class CategoryController
{
    use Concerns\ResolvesAuthenticatedUser;

    /**
     * @throws ValidationException
     */
    public function store(
        StoreCategoryRequest $request,
        Workspace $workspace,
        CreateCategory $createCategory,
    ): RedirectResponse {
        $user = $this->authenticatedUser($request);

        try {
            $createCategory->handle($user, new CreateCategoryCommand(
                workspaceUid: $workspace->uid,
                name: $request->categoryName(),
            ));
        } catch (DomainRuleViolation $exception) {
            throw ValidationException::withMessages([
                'name' => $exception->getMessage(),
            ]);
        }

        $request->session()->put('current_workspace_uid', $workspace->uid);

        return redirect()
            ->route('dashboard')
            ->with('status', 'Category created.');
    }
}
