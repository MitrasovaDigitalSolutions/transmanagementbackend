<?php

namespace Tests\Feature;

use App\Models\Crew;
use App\Models\Schedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_can_create_single_day_schedule(): void
    {
        $this->actingAsAdmin();

        $vehicle = $this->makeVehicle('B-01');
        $driver = $this->makeCrew('driver');
        $kondektur = $this->makeCrew('kondektur');
        $date = now()->addDays(10)->toDateString();

        $response = $this->postJson('/api/v1/schedules', [
            'vehicle_id' => $vehicle->id,
            'schedule_date' => $date,
            'status' => 'in_use',
            'departure_time' => '08:00',
            'destination' => 'Bandung',
            'driver_id' => $driver->id,
            'kondektur_id' => $kondektur->id,
            'notes' => 'Trip reguler',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.saved', 1);

        $this->assertDatabaseCount('schedules', 1);
        $this->assertDatabaseHas('schedules', [
            'vehicle_id' => $vehicle->id,
            'schedule_date' => Carbon::parse($date),
            'status' => 'in_use',
            'destination' => 'Bandung',
            'driver_id' => $driver->id,
            'kondektur_id' => $kondektur->id,
        ]);
    }

    public function test_range_creates_one_row_per_date(): void
    {
        $this->actingAsAdmin();

        $vehicle = $this->makeVehicle('B-01');
        $start = now()->addDays(10);
        $end = $start->copy()->addDays(2);

        $response = $this->postJson('/api/v1/schedules', [
            'vehicle_id' => $vehicle->id,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('meta.saved', 3)
            ->assertJsonCount(3, 'data');

        $this->assertDatabaseCount('schedules', 3);
    }

    public function test_reposting_same_range_updates_instead_of_duplicating(): void
    {
        $this->actingAsAdmin();

        $vehicle = $this->makeVehicle('B-01');
        $start = now()->addDays(10);
        $end = $start->copy()->addDays(2);

        $payload = [
            'vehicle_id' => $vehicle->id,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'status' => 'ready',
            'destination' => 'Bandung',
        ];

        $this->postJson('/api/v1/schedules', $payload)->assertCreated();

        $this->postJson('/api/v1/schedules', array_merge($payload, [
            'status' => 'maintenance',
            'destination' => 'Jakarta',
        ]))->assertCreated()->assertJsonPath('meta.saved', 3);

        $this->assertDatabaseCount('schedules', 3);
        $this->assertDatabaseHas('schedules', [
            'vehicle_id' => $vehicle->id,
            'schedule_date' => Carbon::parse($start->toDateString()),
            'status' => 'maintenance',
            'destination' => 'Jakarta',
        ]);
    }

    public function test_crew_conflict_on_second_date_rolls_back_first_date(): void
    {
        $this->actingAsAdmin();

        $vehicle = $this->makeVehicle('B-01');
        $otherVehicle = $this->makeVehicle('B-02');
        $driver = $this->makeCrew('driver');

        $start = now()->addDays(10);
        $second = $start->copy()->addDay();
        $end = $start->copy()->addDays(2);

        Schedule::create([
            'vehicle_id' => $otherVehicle->id,
            'schedule_date' => $second->toDateString(),
            'driver_id' => $driver->id,
        ]);

        $response = $this->postJson('/api/v1/schedules', [
            'vehicle_id' => $vehicle->id,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'driver_id' => $driver->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('schedule_date');

        $this->assertSame(0, Schedule::where('vehicle_id', $vehicle->id)->count());
        $this->assertDatabaseCount('schedules', 1);
    }

    public function test_kondektur_cannot_be_submitted_as_driver(): void
    {
        $this->actingAsAdmin();

        $vehicle = $this->makeVehicle('B-01');
        $kondektur = $this->makeCrew('kondektur');

        $this->postJson('/api/v1/schedules', [
            'vehicle_id' => $vehicle->id,
            'schedule_date' => now()->addDays(10)->toDateString(),
            'driver_id' => $kondektur->id,
        ])->assertStatus(422)->assertJsonValidationErrors('driver_id');

        $this->assertDatabaseCount('schedules', 0);
    }

    public function test_driver_and_kondektur_cannot_be_the_same_person(): void
    {
        $this->actingAsAdmin();

        $vehicle = $this->makeVehicle('B-01');
        $driver = $this->makeCrew('driver');

        $this->postJson('/api/v1/schedules', [
            'vehicle_id' => $vehicle->id,
            'schedule_date' => now()->addDays(10)->toDateString(),
            'driver_id' => $driver->id,
            'kondektur_id' => $driver->id,
        ])->assertStatus(422)->assertJsonValidationErrors('kondektur_id');

        $this->assertDatabaseCount('schedules', 0);
    }

    public function test_update_rejects_existing_vehicle_and_date_pair(): void
    {
        $this->actingAsAdmin();

        $vehicle = $this->makeVehicle('B-01');
        $otherVehicle = $this->makeVehicle('B-02');
        $date = now()->addDays(10)->toDateString();
        $otherDate = now()->addDays(11)->toDateString();

        Schedule::create([
            'vehicle_id' => $vehicle->id,
            'schedule_date' => $date,
        ]);

        $schedule = Schedule::create([
            'vehicle_id' => $otherVehicle->id,
            'schedule_date' => $otherDate,
        ]);

        $this->putJson("/api/v1/schedules/{$schedule->id}", [
            'vehicle_id' => $vehicle->id,
            'schedule_date' => $date,
        ])->assertStatus(422)->assertJsonValidationErrors('schedule_date');

        $this->assertDatabaseHas('schedules', [
            'id' => $schedule->id,
            'vehicle_id' => $otherVehicle->id,
            'schedule_date' => Carbon::parse($otherDate),
        ]);
    }

    public function test_daily_view_includes_vehicles_without_records_and_maintenance_overlay(): void
    {
        $this->actingAsAdmin();

        $readyVehicle = $this->makeVehicle('B-01');
        $maintenanceVehicle = $this->makeVehicle('B-02');
        $date = now()->addDays(10)->toDateString();

        Schedule::create([
            'vehicle_id' => $maintenanceVehicle->id,
            'schedule_date' => $date,
            'status' => 'maintenance',
        ]);

        $response = $this->getJson('/api/v1/schedules/daily?date='.$date)->assertOk();

        $items = collect($response->json('data'));

        $readyItem = $items->firstWhere('vehicle.id', $readyVehicle->id);
        $this->assertSame('ready', $readyItem['status']);
        $this->assertFalse($readyItem['is_recorded']);
        $this->assertNull($readyItem['schedule']);

        $maintenanceItem = $items->firstWhere('vehicle.id', $maintenanceVehicle->id);
        $this->assertSame('maintenance', $maintenanceItem['status']);
        $this->assertTrue($maintenanceItem['is_recorded']);

        $response->assertJsonPath('meta.recorded', 1)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_crew_role_can_read_schedules_but_cannot_create(): void
    {
        $this->actingAsCrew();

        $date = now()->addDays(10)->toDateString();

        $this->getJson('/api/v1/schedules')->assertOk();
        $this->getJson('/api/v1/schedules/daily?date='.$date)->assertOk();

        $this->postJson('/api/v1/schedules', [
            'vehicle_id' => 1,
            'schedule_date' => $date,
        ])->assertForbidden();
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

    private function makeVehicle(string $code): Vehicle
    {
        $type = VehicleType::firstOrCreate(['name' => 'Bus']);

        return Vehicle::create([
            'vehicle_type_id' => $type->id,
            'code' => $code,
            'name' => "Bus {$code}",
            'plate_number' => 'B '.str_replace('-', '', $code).' XY',
            'seat_capacity' => 40,
        ]);
    }

    private function makeCrew(string $type): Crew
    {
        $attributes = [
            'name' => ucfirst($type).' '.fake()->unique()->numerify('###'),
            'type' => $type,
        ];

        if ($type === 'driver') {
            $attributes += [
                'sim_number' => 'SIM-'.fake()->unique()->numerify('####'),
                'sim_type' => 'B1',
                'sim_expires_at' => now()->addYear()->toDateString(),
            ];
        }

        return Crew::create($attributes);
    }
}
