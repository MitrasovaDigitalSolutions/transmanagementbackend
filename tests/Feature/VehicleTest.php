<?php

namespace Tests\Feature;

use App\Models\Schedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VehicleTest extends TestCase
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

    private function makeType(string $name = 'Bus'): VehicleType
    {
        return VehicleType::create(['name' => $name]);
    }

    private function makeVehicle(VehicleType $type, array $overrides = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'vehicle_type_id' => $type->id,
            'code' => 'B-01',
            'name' => 'Bus 01',
            'plate_number' => 'B 1234 CD',
            'seat_capacity' => 40,
            'status' => 'active',
        ], $overrides));
    }

    public function test_admin_can_manage_vehicles(): void
    {
        $type = $this->makeType();

        $response = $this->postJson('/api/v1/vehicles', [
            'vehicle_type_id' => $type->id,
            'code' => 'B-01',
            'name' => 'Bus 01',
            'plate_number' => 'B 1234 CD',
            'seat_capacity' => 40,
            'year' => 2024,
            'status' => 'active',
        ])->assertCreated()
            ->assertJsonPath('data.code', 'B-01')
            ->assertJsonPath('data.vehicle_type.id', $type->id);

        $id = $response->json('data.id');

        $this->getJson('/api/v1/vehicles')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1);

        $this->getJson("/api/v1/vehicles/{$id}")
            ->assertOk()
            ->assertJsonPath('data.code', 'B-01')
            ->assertJsonPath('data.vehicle_type.id', $type->id)
            ->assertJsonStructure(['data' => ['attachments']]);

        $this->putJson("/api/v1/vehicles/{$id}", ['name' => 'Bus Satu', 'status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Bus Satu')
            ->assertJsonPath('data.status', 'inactive');

        $this->deleteJson("/api/v1/vehicles/{$id}")->assertOk();
        $this->assertDatabaseMissing('vehicles', ['id' => $id]);
    }

    public function test_vehicle_code_and_plate_number_must_be_unique(): void
    {
        $type = $this->makeType();
        $this->makeVehicle($type);

        $this->postJson('/api/v1/vehicles', [
            'vehicle_type_id' => $type->id,
            'code' => 'B-01',
            'name' => 'Bus Lain',
            'plate_number' => 'B 9999 ZZ',
            'seat_capacity' => 30,
        ])->assertStatus(422)->assertJsonValidationErrors('code');

        $this->postJson('/api/v1/vehicles', [
            'vehicle_type_id' => $type->id,
            'code' => 'B-02',
            'name' => 'Bus Lain',
            'plate_number' => 'B 1234 CD',
            'seat_capacity' => 30,
        ])->assertStatus(422)->assertJsonValidationErrors('plate_number');
    }

    public function test_vehicles_can_be_filtered_by_type_status_and_search(): void
    {
        $bus = $this->makeType('Bus');
        $elf = $this->makeType('Elf');

        $this->makeVehicle($bus, ['code' => 'B-01', 'name' => 'Bus Jakarta', 'plate_number' => 'B 1111 AA']);
        $this->makeVehicle($bus, ['code' => 'B-02', 'name' => 'Bus Bandung', 'plate_number' => 'B 2222 BB', 'status' => 'inactive']);
        $this->makeVehicle($elf, ['code' => 'E-01', 'name' => 'Elf Surabaya', 'plate_number' => 'L 3333 CC']);

        $this->getJson("/api/v1/vehicles?vehicle_type_id={$elf->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'E-01');

        $this->getJson('/api/v1/vehicles?status=inactive')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'B-02');

        $this->getJson('/api/v1/vehicles?q=jakarta')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'B-01');

        $this->getJson('/api/v1/vehicles?q=3333')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'E-01');
    }

    public function test_vehicle_with_schedule_cannot_be_deleted(): void
    {
        $type = $this->makeType();
        $vehicle = $this->makeVehicle($type);

        Schedule::create([
            'vehicle_id' => $vehicle->id,
            'schedule_date' => now()->toDateString(),
            'status' => 'ready',
        ]);

        $this->deleteJson("/api/v1/vehicles/{$vehicle->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id]);
    }

    public function test_crew_can_view_vehicles_but_cannot_create_them(): void
    {
        $type = $this->makeType();
        $vehicle = $this->makeVehicle($type);

        $vehicle->attachments()->create([
            'category' => 'thumbnail',
            'path' => "vehicles/{$vehicle->id}/thumb.jpg",
            'original_name' => 'thumb.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 100,
        ]);
        $vehicle->attachments()->create([
            'category' => 'document',
            'path' => "vehicles/{$vehicle->id}/stnk.pdf",
            'original_name' => 'stnk.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
        ]);

        $crewUser = User::factory()->create();
        $crewUser->assignRole('crew');
        Sanctum::actingAs($crewUser);

        $this->getJson("/api/v1/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.attachments')
            ->assertJsonPath('data.attachments.0.category', 'thumbnail');

        $this->postJson('/api/v1/vehicles', [
            'vehicle_type_id' => $type->id,
            'code' => 'B-99',
            'name' => 'Bus Crew',
            'plate_number' => 'B 9999 XX',
            'seat_capacity' => 30,
        ])->assertForbidden();
    }
}
