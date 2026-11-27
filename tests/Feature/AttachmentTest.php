<?php

namespace Tests\Feature;

use App\Models\Crew;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttachmentTest extends TestCase
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

    private function makeVehicle(): Vehicle
    {
        $type = VehicleType::create(['name' => 'Bus']);

        return Vehicle::create([
            'vehicle_type_id' => $type->id,
            'code' => 'B-01',
            'name' => 'Bus 01',
            'plate_number' => 'B 1234 CD',
            'seat_capacity' => 40,
            'status' => 'active',
        ]);
    }

    private function makeCrew(): Crew
    {
        return Crew::create([
            'name' => 'Budi',
            'type' => 'driver',
            'sim_number' => 'SIM-001',
            'sim_type' => 'B2',
            'sim_expires_at' => '2027-01-01',
        ]);
    }

    private function upload(string $uri, array $data)
    {
        return $this->post($uri, $data, ['Accept' => 'application/json']);
    }

    public function test_vehicle_thumbnail_upload_returns_url(): void
    {
        $vehicle = $this->makeVehicle();

        $response = $this->upload("/api/v1/vehicles/{$vehicle->id}/attachments", [
            'category' => 'thumbnail',
            'label' => 'Depan',
            'file' => UploadedFile::fake()->image('depan.jpg', 600, 400),
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.category', 'thumbnail');

        $path = $response->json('data.path');

        Storage::disk('public')->assertExists($path);
        $this->assertNotNull($response->json('data.url'));
    }

    public function test_new_thumbnail_replaces_old_file_and_row(): void
    {
        $vehicle = $this->makeVehicle();

        $oldPath = $this->upload("/api/v1/vehicles/{$vehicle->id}/attachments", [
            'category' => 'thumbnail',
            'file' => UploadedFile::fake()->image('lama.jpg'),
        ])->assertCreated()->json('data.path');

        $newPath = $this->upload("/api/v1/vehicles/{$vehicle->id}/attachments", [
            'category' => 'thumbnail',
            'file' => UploadedFile::fake()->image('baru.jpg'),
        ])->assertCreated()->json('data.path');

        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);
        $this->assertDatabaseCount('attachments', 1);
        $this->assertDatabaseHas('attachments', ['path' => $newPath]);
    }

    public function test_thumbnail_rejects_non_image(): void
    {
        $vehicle = $this->makeVehicle();

        $this->upload("/api/v1/vehicles/{$vehicle->id}/attachments", [
            'category' => 'thumbnail',
            'file' => UploadedFile::fake()->create('stnk.pdf', 100, 'application/pdf'),
        ])->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors('file');
    }

    public function test_vehicle_pdf_document_is_stored_on_private_disk(): void
    {
        $vehicle = $this->makeVehicle();

        $response = $this->upload("/api/v1/vehicles/{$vehicle->id}/attachments", [
            'category' => 'document',
            'label' => 'STNK',
            'file' => UploadedFile::fake()->create('stnk.pdf', 100, 'application/pdf'),
        ])->assertCreated()
            ->assertJsonPath('data.category', 'document');

        $path = $response->json('data.path');

        Storage::disk('private')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
        $this->assertStringContainsString('signature=', $response->json('data.url'));
    }

    public function test_crew_document_upload_only_accepts_documents(): void
    {
        $crew = $this->makeCrew();

        $response = $this->upload("/api/v1/crews/{$crew->id}/attachments", [
            'category' => 'document',
            'label' => 'SIM A',
            'file' => UploadedFile::fake()->image('sim.jpg'),
        ])->assertCreated()
            ->assertJsonPath('data.category', 'document');

        Storage::disk('private')->assertExists($response->json('data.path'));

        $this->upload("/api/v1/crews/{$crew->id}/attachments", [
            'category' => 'thumbnail',
            'file' => UploadedFile::fake()->image('foto.jpg'),
        ])->assertStatus(422);
    }

    public function test_crew_role_cannot_upload_attachments(): void
    {
        $vehicle = $this->makeVehicle();

        $crewUser = User::factory()->create();
        $crewUser->assignRole('crew');
        Sanctum::actingAs($crewUser);

        $this->upload("/api/v1/vehicles/{$vehicle->id}/attachments", [
            'category' => 'thumbnail',
            'file' => UploadedFile::fake()->image('foto.jpg'),
        ])->assertForbidden();
    }
}
