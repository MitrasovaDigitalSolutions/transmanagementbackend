<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['password' => 'password']);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_login_returns_token(): void
    {
        $user = User::factory()->create([
            'email' => 'login@example.test',
            'password' => 'secret123',
        ]);
        $user->assignRole('admin');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'login@example.test',
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['token', 'user' => ['id', 'name', 'email', 'roles']],
            ]);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_login_with_wrong_password_returns_422(): void
    {
        User::factory()->create([
            'email' => 'login@example.test',
            'password' => 'secret123',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'login@example.test',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.email.0', 'Kredensial tidak cocok.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_me_returns_user_with_roles_and_permissions(): void
    {
        $this->actingAsRole('admin');

        $response = $this->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.roles.0', 'admin');

        $this->assertContains('users.view', $response->json('data.permissions'));
    }

    public function test_logout_deletes_the_current_token(): void
    {
        $user = User::factory()->create([
            'email' => 'logout@example.test',
            'password' => 'secret123',
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'logout@example.test',
            'password' => 'secret123',
        ])->json('data.token');

        $this->assertNotNull($token);
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();

        $this->assertTrue($user->exists());
    }

    public function test_guest_cannot_access_me(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
}
