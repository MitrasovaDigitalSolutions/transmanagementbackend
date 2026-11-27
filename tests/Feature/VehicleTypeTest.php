<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VehicleTypeTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('private');
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        Sanctum::actingAs($this->admin);
    }

    public function test_admin_can_manage_vehicle_types(): void
    {
        $this->getJson('/api/v1/vehicle-types')
            ->assertOk()
            ->assertJsonPath('success', true);

        $id = $this->postJson('/api/v1/vehicle-types', [
            'name' => 'Bus',
            'description' => 'Bus besar',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Bus')
            ->json('data.id');

        $this->getJson("/api/v1/vehicle-types/{$id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Bus');

        $this->putJson("/api/v1/vehicle-types/{$id}", ['name' => 'Bus Besar'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Bus Besar');

        $this->assertDatabaseHas('vehicle_types', ['id' => $id, 'name' => 'Bus Besar']);

        $this->deleteJson("/api/v1/vehicle-types/{$id}")->assertOk();
        $this->assertDatabaseMissing('vehicle_types', ['id' => $id]);
    }

    public function test_vehicle_type_name_must_be_unique(): void
    {
        VehicleType::create(['name' => 'Bus']);

        $this->postJson('/api/v1/vehicle-types', ['name' => 'Bus'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors('name');
    }

    public function test_vehicle_type_in_use_cannot_be_deleted(): void
    {
        $type = VehicleType::create(['name' => 'Bus']);

        Vehicle::create([
            'vehicle_type_id' => $type->id,
            'code' => 'B-01',
            'name' => 'Bus 01',
            'plate_number' => 'B 1234 CD',
            'seat_capacity' => 40,
            'status' => 'active',
        ]);

        $this->deleteJson("/api/v1/vehicle-types/{$type->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('vehicle_types', ['id' => $type->id]);
    }
}
