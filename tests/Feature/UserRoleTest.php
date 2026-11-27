<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_admin_can_list_users(): void
    {
        $this->actingAsRole('admin');
        User::factory()->count(2)->create();

        $response = $this->getJson('/api/v1/users');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'name', 'email', 'roles']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_admin_can_create_user_with_role(): void
    {
        $this->actingAsRole('admin');

        $response = $this->postJson('/api/v1/users', [
            'name' => 'Operator Satu',
            'email' => 'operator@example.test',
            'password' => 'secret123',
            'roles' => ['crew'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.roles.0', 'crew');

        $this->assertArrayNotHasKey('password', $response->json('data'));

        $created = User::where('email', 'operator@example.test')->firstOrFail();
        $this->assertTrue($created->hasRole('crew'));
        $this->assertSame('crew', Role::findByName('crew', 'web')->name);
    }

    public function test_admin_can_update_user_roles(): void
    {
        $this->actingAsRole('admin');
        $target = User::factory()->create();
        $target->assignRole('crew');

        $response = $this->putJson("/api/v1/users/{$target->id}", [
            'name' => 'Nama Baru',
            'roles' => ['admin'],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Nama Baru')
            ->assertJsonPath('data.roles.0', 'admin');

        $target->refresh();
        $this->assertTrue($target->hasRole('admin'));
        $this->assertFalse($target->hasRole('crew'));
    }

    public function test_crew_cannot_access_users_or_roles(): void
    {
        $this->actingAsRole('crew');

        $this->getJson('/api/v1/users')->assertForbidden();
        $this->getJson('/api/v1/roles')->assertForbidden();
    }

    public function test_superadmin_can_create_and_update_role(): void
    {
        $this->actingAsRole('superadmin');

        $created = $this->postJson('/api/v1/roles', [
            'name' => 'operator',
            'permissions' => ['vehicles.view', 'schedules.view'],
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.name', 'operator');

        $this->assertContains('vehicles.view', $created->json('data.permissions'));

        $roleId = $created->json('data.id');

        $updated = $this->putJson("/api/v1/roles/{$roleId}", [
            'name' => 'operator-updated',
            'permissions' => ['crews.view'],
        ]);

        $updated->assertOk()
            ->assertJsonPath('data.name', 'operator-updated')
            ->assertJsonPath('data.permissions', ['crews.view']);

        $this->assertDatabaseHas('roles', ['id' => $roleId, 'name' => 'operator-updated']);
    }

    public function test_superadmin_role_cannot_be_edited(): void
    {
        $this->actingAsRole('superadmin');
        $superadmin = Role::findByName('superadmin', 'web');

        $this->putJson("/api/v1/roles/{$superadmin->id}", [
            'name' => 'root',
            'permissions' => ['vehicles.view'],
        ])->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('superadmin', $superadmin->fresh()->name);
        $this->assertTrue($superadmin->fresh()->hasPermissionTo('roles.update'));
    }

    public function test_role_in_use_cannot_be_deleted(): void
    {
        $this->actingAsRole('superadmin');
        $user = User::factory()->create();
        $user->assignRole('crew');

        $crew = Role::findByName('crew', 'web');

        $this->deleteJson("/api/v1/roles/{$crew->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('roles', ['id' => $crew->id]);
    }

    public function test_superadmin_role_cannot_be_deleted(): void
    {
        $this->actingAsRole('superadmin');

        $superadmin = Role::findByName('superadmin', 'web');

        $this->deleteJson("/api/v1/roles/{$superadmin->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('roles', ['id' => $superadmin->id]);
    }

    public function test_admin_cannot_delete_own_account(): void
    {
        $admin = $this->actingAsRole('admin');

        $this->deleteJson("/api/v1/users/{$admin->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_admin_cannot_manage_superadmin_accounts_or_roles(): void
    {
        $this->actingAsRole('admin');

        $this->postJson('/api/v1/users', [
            'name' => 'Escalate',
            'email' => 'escalate@example.test',
            'password' => 'secret123',
            'roles' => ['superadmin'],
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'escalate@example.test']);

        $superadmin = User::factory()->create();
        $superadmin->assignRole('superadmin');

        $this->putJson("/api/v1/users/{$superadmin->id}", ['name' => 'Hijacked'])->assertForbidden();
        $this->deleteJson("/api/v1/users/{$superadmin->id}")->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $superadmin->id, 'name' => $superadmin->name]);

        $this->getJson('/api/v1/roles')->assertOk();
        $this->postJson('/api/v1/roles', ['name' => 'role-baru'])->assertForbidden();
    }

    public function test_last_superadmin_cannot_be_demoted(): void
    {
        $superadmin = $this->actingAsRole('superadmin');

        $this->putJson("/api/v1/users/{$superadmin->id}", ['roles' => ['admin']])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertTrue($superadmin->fresh()->hasRole('superadmin'));
    }

    public function test_guest_cannot_access_user_or_role_endpoints(): void
    {
        $this->getJson('/api/v1/users')->assertUnauthorized();
        $this->getJson('/api/v1/roles')->assertUnauthorized();
    }
}
