<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

final class SystemAdminGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_administer_system_ability_is_restricted_to_system_admins(): void
    {
        $admin = $this->user(isSystemAdmin: true);
        $member = $this->user(isSystemAdmin: false, email: 'member@example.test');

        $this->assertTrue(Gate::forUser($admin)->allows('administer-system'));
        $this->assertFalse(Gate::forUser($member)->allows('administer-system'));
    }

    /**
     * Locks in the invariant behind the gate: a new admin route added outside the
     * can:administer-system group would be silently unguarded, so fail loudly here
     * instead of shipping an open admin endpoint.
     */
    public function test_every_admin_route_is_guarded_by_the_administer_system_gate(): void
    {
        $adminRoutes = array_values(array_filter(
            RouteFacade::getRoutes()->getRoutes(),
            static fn (Route $route): bool => str_starts_with($route->uri(), 'admin/'),
        ));

        $this->assertNotEmpty($adminRoutes, 'Expected at least one admin/* route to exist.');

        foreach ($adminRoutes as $route) {
            $this->assertContains(
                'can:administer-system',
                $route->gatherMiddleware(),
                sprintf('Route [%s] must be guarded by the administer-system gate.', $route->uri()),
            );
        }
    }

    private function user(bool $isSystemAdmin, string $email = 'admin@example.test'): User
    {
        $user = User::query()->create([
            'name' => $isSystemAdmin ? 'System Admin' : 'Member',
            'email' => $email,
            'password' => Hash::make('password'),
        ]);

        if ($isSystemAdmin) {
            $user->forceFill(['is_system_admin' => true])->save();
        }

        return $user;
    }
}
