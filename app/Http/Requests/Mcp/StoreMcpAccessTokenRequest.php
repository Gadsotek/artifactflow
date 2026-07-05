<?php

declare(strict_types=1);

namespace App\Http\Requests\Mcp;

use App\Application\Mcp\McpAccessTokenIssuer;
use App\Http\Requests\AppFormRequest;
use Illuminate\Validation\Rule;

final class StoreMcpAccessTokenRequest extends AppFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:120'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', Rule::in(McpAccessTokenIssuer::allowedScopes())],
            'workspace_uids' => ['nullable', 'array'],
            'workspace_uids.*' => ['required', 'string'],
            'all_workspaces' => ['nullable', 'boolean'],
            'expires_in_days' => ['required', 'integer', 'min:1', 'max:365'],
            'password' => ['required', 'string'],
            'code' => ['required', 'string'],
        ];
    }

    public function tokenName(): string
    {
        $name = trim($this->string('name')->toString());

        return $name === '' ? 'MCP token' : $name;
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        return $this->stringList('scopes');
    }

    /**
     * @return list<string>
     */
    public function workspaceUids(): array
    {
        return $this->stringList('workspace_uids');
    }

    public function allWorkspaces(): bool
    {
        return $this->boolean('all_workspaces');
    }

    public function expiresInDays(): int
    {
        return $this->integer('expires_in_days');
    }

    public function password(): string
    {
        return $this->string('password')->toString();
    }

    public function twoFactorCode(): string
    {
        return $this->string('code')->toString();
    }

    /**
     * @return list<string>
     */
    private function stringList(string $field): array
    {
        $values = $this->input($field, []);

        if (!is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, 'is_string'));
    }
}
