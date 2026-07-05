<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserModelGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_admin_flag_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        User::query()->create([
            'name' => 'Mass Assignment User',
            'email' => 'mass-assignment@example.test',
            'password' => 'correct horse battery staple',
            'is_system_admin' => true,
        ]);
    }
}
