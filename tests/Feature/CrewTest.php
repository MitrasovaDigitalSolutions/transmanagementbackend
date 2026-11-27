<?php

namespace Tests\Feature;

use App\Models\Crew;
use App\Models\Schedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CrewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_can_crud_driver_with_sim(): void
    {
        $this->actingAsAdmin();

        $payload = $this->driverPayload();

        $create = $this->postJson('/api/v1/crews', $payload);
        $create->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'driver');

        $crewId = $create->json('data.id');

        $this->getJson('/api/v1/crews')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/crews/{$crewId}")
            ->assertOk()
            ->assertJsonPath('data.id', $crewId)
            ->assertJsonPath('data.name', $payload['name']);

        $this->putJson("/api/v1/crews/{$crewId}", ['name' => 'Budi Updated'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Budi Updated');

        $this->deleteJson("/api/v1/crews/{$crewId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('crews', ['id' => $crewId]);
    }

    public function test_driver_without_sim_is_rejected(): void
    {
        $this->actingAsAdmin();

        $payload = $this->driverPayload();
        unset($payload['sim_number'], $payload['sim_type'], $payload['sim_expires_at']);

        $this->postJson('/api/v1/crews', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sim_number', 'sim_type', 'sim_expires_at']);
    }

    public function test_kondektur_without_sim_is_allowed(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/crews', [
            'name' => 'Siti',
            'type' => 'kondektur',
            'phone' => '081200000000',
        ])->assertCreated()
            ->assertJsonPath('data.type', 'kondektur')
            ->assertJsonPath('data.sim_number', null);
    }

    public function test_nik_must_be_unique_and_sixteen_digits(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/crews', $this->driverPayload([
            'nik' => '1234567890123456',
        ]))->assertCreated();

        $this->postJson('/api/v1/crews', $this->driverPayload([
            'name' => 'Duplicate',
            'nik' => '1234567890123456',
        ]))->assertStatus(422)->assertJsonValidationErrors('nik');

        $this->postJson('/api/v1/crews', $this->driverPayload([
            'name' => 'Invalid',
            'nik' => '123',
        ]))->assertStatus(422)->assertJsonValidationErrors('nik');
    }

    public function test_index_can_filter_by_type(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/crews', $this->driverPayload([
            'nik' => '1111111111111111',
        ]))->assertCreated();

        $this->postJson('/api/v1/crews', [
            'name' => 'Kondektur Satu',
            'type' => 'kondektur',
        ])->assertCreated();

        $this->getJson('/api/v1/crews?type=driver')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'driver');
    }

    public function test_crew_referenced_by_schedule_cannot_be_deleted(): void
    {
        $this->actingAsAdmin();

        $type = VehicleType::create(['name' => 'Bus']);
        $vehicle = Vehicle::create([
            'vehicle_type_id' => $type->id,
            'code' => 'B-01',
            'name' => 'Bus Satu',
            'plate_number' => 'B 1234 XY',
            'seat_capacity' => 40,
        ]);

        $driver = Crew::create([
            'name' => 'Budi',
            'type' => 'driver',
            'sim_number' => 'SIM-001',
            'sim_type' => 'B1',
            'sim_expires_at' => now()->addYear()->toDateString(),
        ]);

        Schedule::create([
            'vehicle_id' => $vehicle->id,
            'schedule_date' => now()->addDays(10)->toDateString(),
            'driver_id' => $driver->id,
        ]);

        $this->deleteJson("/api/v1/crews/{$driver->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('crews', ['id' => $driver->id]);
    }

    public function test_crew_role_cannot_access_crews_index(): void
    {
        $this->actingAsCrew();

        $this->getJson('/api/v1/crews')->assertForbidden();
    }

    private function actingAsAdmin(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        Sanctum::actingAs($user);
    }

    private function actingAsCrew(): void
    {
        $user = User::factory()->create();
        $user->assignRole('crew');
        Sanctum::actingAs($user);
    }

    private function driverPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Budi',
            'type' => 'driver',
            'nik' => '1234567890123456',
            'phone' => '08123456789',
            'sim_number' => 'SIM-001',
            'sim_type' => 'B1',
            'sim_expires_at' => now()->addYear()->toDateString(),
            'address' => 'Jl. Test',
            'status' => 'active',
        ], $overrides);
    }
}
