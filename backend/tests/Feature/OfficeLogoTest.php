<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\User;
use App\Services\OfficeLogoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OfficeLogoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('public');
    }

    public function test_super_admin_can_upload_logo_when_creating_office(): void
    {
        $super = User::where('email', 'super@travel.kw')->first();
        $file = UploadedFile::fake()->image('branch.png', 120, 120);

        $response = $this->actingAsWithOffice($super)
            ->post('/api/offices', [
                'office_code' => 'LOGO-A',
                'office_name' => 'فرع الشعار A',
                'logo' => $file,
            ])
            ->assertCreated()
            ->assertJsonStructure(['logo', 'logo_url']);

        $office = Office::where('office_code', 'LOGO-A')->first();
        $this->assertNotNull($office->logo);
        $this->assertTrue(str_starts_with($office->logo, 'office-logos/'.$office->id.'/'));
        Storage::disk('public')->assertExists($office->logo);
        $this->assertNotNull($response->json('logo_url'));
    }

    public function test_super_admin_can_replace_office_logo(): void
    {
        $super = User::where('email', 'super@travel.kw')->first();
        $office = Office::where('office_code', 'MAIN')->first();
        $first = UploadedFile::fake()->image('first.png');
        $second = UploadedFile::fake()->image('second.webp');

        $this->actingAsWithOffice($super)
            ->post("/api/offices/{$office->id}/logo", ['logo' => $first])
            ->assertOk();

        $office->refresh();
        $oldPath = $office->logo;
        Storage::disk('public')->assertExists($oldPath);

        $this->actingAsWithOffice($super)
            ->post("/api/offices/{$office->id}/logo", ['logo' => $second])
            ->assertOk();

        $office->refresh();
        $this->assertNotSame($oldPath, $office->logo);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($office->logo);
    }

    public function test_invalid_logo_type_is_rejected(): void
    {
        $super = User::where('email', 'super@travel.kw')->first();
        $office = Office::where('office_code', 'MAIN')->first();
        $file = UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf');

        $this->actingAsWithOffice($super)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/offices/{$office->id}/logo", ['logo' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['logo']);
    }

    public function test_oversized_logo_is_rejected(): void
    {
        $super = User::where('email', 'super@travel.kw')->first();
        $office = Office::where('office_code', 'MAIN')->first();
        $file = UploadedFile::fake()->create('logo.png', 3000, 'image/png');

        $this->actingAsWithOffice($super)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/api/offices/{$office->id}/logo", ['logo' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['logo']);
    }

    public function test_super_admin_can_remove_office_logo(): void
    {
        $super = User::where('email', 'super@travel.kw')->first();
        $office = Office::where('office_code', 'MAIN')->first();

        $this->actingAsWithOffice($super)
            ->post("/api/offices/{$office->id}/logo", ['logo' => UploadedFile::fake()->image('logo.jpg')])
            ->assertOk();

        $office->refresh();
        $path = $office->logo;

        $this->actingAsWithOffice($super)
            ->deleteJson("/api/offices/{$office->id}/logo")
            ->assertOk()
            ->assertJsonPath('logo', null);

        Storage::disk('public')->assertMissing($path);
        $this->assertNull($office->fresh()->logo);
    }

    public function test_office_without_logo_still_works(): void
    {
        $super = User::where('email', 'super@travel.kw')->first();

        $this->actingAsWithOffice($super)
            ->postJson('/api/offices', [
                'office_code' => 'NO-LOGO',
                'office_name' => 'فرع بدون شعار',
            ])
            ->assertCreated()
            ->assertJsonPath('logo', null)
            ->assertJsonPath('logo_url', null);
    }

    public function test_bootstrap_includes_logo_url_for_current_office(): void
    {
        $sales = User::where('email', 'sales@travel.kw')->first();
        $office = Office::find($sales->office_id);
        $logos = app(OfficeLogoService::class);
        $office->update(['logo' => UploadedFile::fake()->image('main.png')->store('office-logos/'.$office->id, 'public')]);

        $this->actingAsWithOffice($sales)
            ->getJson('/api/bootstrap')
            ->assertOk()
            ->assertJsonPath('current_office.logo_url', $logos->url($office->fresh()->logo));
    }

    public function test_admin_can_upload_logo_for_own_office(): void
    {
        $admin = User::where('role', 'admin')->first();
        $office = Office::find($admin->office_id);

        $this->actingAsWithOffice($admin)
            ->post("/api/offices/{$office->id}/logo", ['logo' => UploadedFile::fake()->image('logo.png')])
            ->assertOk();
    }
}
