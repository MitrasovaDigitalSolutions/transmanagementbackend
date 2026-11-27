<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Crew;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MediaTest extends TestCase
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

    private function attach(Vehicle|Crew $owner, string $category, string $fileName, string $label): Attachment
    {
        $disk = $category === 'thumbnail' ? 'public' : 'private';
        $folder = $owner instanceof Vehicle ? 'vehicles' : 'crews';
        $path = "{$folder}/{$owner->id}/".uniqid().'.'.pathinfo($fileName, PATHINFO_EXTENSION);

        Storage::disk($disk)->put($path, 'dummy');

        $data = [
            'category' => $category,
            'label' => $label,
            'path' => $path,
            'original_name' => $fileName,
            'mime_type' => 'image/jpeg',
            'size' => 100,
        ];

        return $owner instanceof Vehicle
            ? $owner->attachments()->create($data)
            : $owner->documents()->create($data);
    }

    public function test_admin_can_list_and_filter_media(): void
    {
        $vehicle = $this->makeVehicle();
        $crew = $this->makeCrew();

        $this->attach($vehicle, 'thumbnail', 'thumb.jpg', 'Foto Depan');
        $this->attach($vehicle, 'document', 'stnk.pdf', 'STNK');
        $this->attach($crew, 'document', 'sim.jpg', 'SIM A');

        $this->getJson('/api/v1/media')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonStructure(['data' => [['owner' => ['type', 'id', 'name']]]]);

        $this->getJson('/api/v1/media?category=document')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/media?attachable_type=crew')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.owner.type', 'crew')
            ->assertJsonPath('data.0.owner.name', 'Budi');

        $this->getJson('/api/v1/media?q=stnk')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.original_name', 'stnk.pdf');
    }

    public function test_admin_can_update_media_label(): void
    {
        $attachment = $this->attach($this->makeVehicle(), 'thumbnail', 'thumb.jpg', 'Foto Depan');

        $this->putJson("/api/v1/media/{$attachment->id}", [
            'label' => 'Foto Samping',
            'expires_at' => '2027-12-31',
        ])->assertOk()
            ->assertJsonPath('data.label', 'Foto Samping');

        $this->assertDatabaseHas('attachments', [
            'id' => $attachment->id,
            'label' => 'Foto Samping',
            'expires_at' => '2027-12-31 00:00:00',
        ]);
    }

    public function test_replacing_media_removes_old_file(): void
    {
        $attachment = $this->attach($this->makeVehicle(), 'thumbnail', 'lama.jpg', 'Foto');
        $oldPath = $attachment->path;

        $response = $this->post("/api/v1/media/{$attachment->id}/replace", [
            'file' => UploadedFile::fake()->image('baru.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $newPath = $response->json('data.path');

        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);
        $this->assertDatabaseHas('attachments', [
            'id' => $attachment->id,
            'path' => $newPath,
            'original_name' => 'baru.jpg',
        ]);
    }

    public function test_deleting_media_removes_file_and_row(): void
    {
        $attachment = $this->attach($this->makeVehicle(), 'thumbnail', 'thumb.jpg', 'Foto');

        $this->deleteJson("/api/v1/media/{$attachment->id}")->assertOk();

        Storage::disk('public')->assertMissing($attachment->path);
        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
    }

    public function test_signed_file_url_works_without_auth(): void
    {
        $attachment = $this->attach($this->makeVehicle(), 'document', 'stnk.pdf', 'STNK');
        $parts = parse_url($attachment->url);

        $this->get($parts['path'].'?'.$parts['query'], ['Accept' => 'application/json'])
            ->assertOk();
    }

    public function test_expired_signed_file_url_is_rejected(): void
    {
        $attachment = $this->attach($this->makeVehicle(), 'document', 'stnk.pdf', 'STNK');

        $url = URL::temporarySignedRoute(
            'attachments.file',
            now()->subMinute(),
            ['attachment' => $attachment->id]
        );

        $parts = parse_url($url);

        $this->get($parts['path'].'?'.$parts['query'], ['Accept' => 'application/json'])
            ->assertStatus(403);
    }

    public function test_crew_cannot_access_media_gallery(): void
    {
        $crewUser = User::factory()->create();
        $crewUser->assignRole('crew');
        Sanctum::actingAs($crewUser);

        $this->getJson('/api/v1/media')->assertForbidden();
    }
}
