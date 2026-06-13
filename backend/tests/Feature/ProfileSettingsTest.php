<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->password = env('SEED_USER_PASSWORD', 'travel-erp-test-secret');
    }

    public function test_user_can_change_password_with_correct_current_password(): void
    {
        $admin = User::where('role', 'admin')->first();
        $newPassword = 'NewSecurePass123';

        $this->actingAsWithOffice($admin)
            ->patchJson('/api/profile/password', [
                'current_password' => $this->password,
                'password' => $newPassword,
                'password_confirmation' => $newPassword,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'تم تحديث كلمة المرور بنجاح');

        $admin->refresh();
        $this->assertTrue(Hash::check($newPassword, $admin->password));
        $this->assertFalse((bool) $admin->must_change_password);

        $this->postJson('/api/login', [
            'email' => $admin->email,
            'password' => $newPassword,
        ])->assertOk();
    }

    public function test_password_change_rejects_wrong_current_password(): void
    {
        $admin = User::where('role', 'admin')->first();

        $this->actingAsWithOffice($admin)
            ->patchJson('/api/profile/password', [
                'current_password' => 'WrongCurrentPass',
                'password' => 'NewSecurePass123',
                'password_confirmation' => 'NewSecurePass123',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        $this->assertTrue(Hash::check($this->password, $admin->fresh()->password));
    }

    public function test_profile_update_rejects_duplicate_email(): void
    {
        $admin = User::where('role', 'admin')->first();
        $other = User::where('email', 'sales@travel.kw')->first();

        $this->actingAsWithOffice($admin)
            ->patchJson('/api/profile', [
                'name' => $admin->name,
                'email' => $other->email,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_password_change_rejects_confirmation_mismatch(): void
    {
        $admin = User::where('role', 'admin')->first();

        $this->actingAsWithOffice($admin)
            ->patchJson('/api/profile/password', [
                'current_password' => $this->password,
                'password' => 'NewSecurePass123',
                'password_confirmation' => 'DifferentPass123',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->assertTrue(Hash::check($this->password, $admin->fresh()->password));
    }

    public function test_user_can_update_name_and_email(): void
    {
        $admin = User::where('role', 'admin')->first();
        $newEmail = 'admin-updated@travel.kw';

        $this->actingAsWithOffice($admin)
            ->patchJson('/api/profile', [
                'name' => 'مدير محدّث',
                'email' => $newEmail,
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'مدير محدّث')
            ->assertJsonPath('user.email', $newEmail);

        $this->assertSame($newEmail, User::find($admin->id)->email);
    }
}
