<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\User;
use App\Services\OfficeProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->password = env('SEED_USER_PASSWORD', 'travel-erp-test-secret');
    }

    public function test_inactive_user_cannot_login(): void
    {
        $admin = User::where('role', 'admin')->first();

        $created = $this->actingAsWithOffice($admin)
            ->postJson('/api/users', [
                'name' => 'Inactive User',
                'email' => 'inactive@travel.kw',
                'password' => 'SecurePass123',
                'role' => 'sales',
            ])
            ->assertCreated();

        $this->actingAsWithOffice($admin)
            ->patchJson('/api/users/'.$created->json('id'), ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('is_active', false);

        $this->postJson('/api/login', [
            'email' => 'inactive@travel.kw',
            'password' => 'SecurePass123',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_super_admin_can_change_user_role(): void
    {
        $super = User::where('email', 'super@travel.kw')->first();
        $sales = User::where('email', 'sales@travel.kw')->first();

        $this->actingAsWithOffice($super)
            ->patchJson("/api/users/{$sales->id}", ['role' => 'accountant'])
            ->assertOk()
            ->assertJsonPath('role', 'accountant');

        $this->assertSame('accountant', User::find($sales->id)->role);
    }

    public function test_office_admin_cannot_assign_super_admin_role(): void
    {
        $admin = User::where('role', 'admin')->first();
        $sales = User::where('email', 'sales@travel.kw')->first();

        $this->actingAsWithOffice($admin)
            ->patchJson("/api/users/{$sales->id}", ['role' => 'super_admin'])
            ->assertStatus(422);
    }

    public function test_super_admin_can_reassign_user_office(): void
    {
        $super = User::where('email', 'super@travel.kw')->first();
        $officeB = app(OfficeProvisioningService::class)->createOffice([
            'office_code' => 'USR-B',
            'office_name' => 'فرع المستخدمين B',
        ]);
        $sales = User::where('email', 'sales@travel.kw')->first();

        $this->actingAsWithOffice($super)
            ->patchJson("/api/users/{$sales->id}", [
                'office_id' => $officeB->id,
            ])
            ->assertOk()
            ->assertJsonPath('office_id', $officeB->id);

        $this->assertSame($officeB->id, User::find($sales->id)->office_id);
    }

    public function test_office_admin_cannot_reassign_user_to_other_office(): void
    {
        $admin = User::where('role', 'admin')->first();
        $officeB = app(OfficeProvisioningService::class)->createOffice([
            'office_code' => 'USR-C',
            'office_name' => 'فرع المستخدمين C',
        ]);
        $sales = User::where('email', 'sales@travel.kw')->first();
        $originalOfficeId = $sales->office_id;

        $this->actingAsWithOffice($admin)
            ->patchJson("/api/users/{$sales->id}", [
                'office_id' => $officeB->id,
            ])
            ->assertOk()
            ->assertJsonPath('office_id', $originalOfficeId);

        $this->assertSame($originalOfficeId, User::find($sales->id)->office_id);
    }

    public function test_password_reset_allows_login_with_new_password(): void
    {
        $admin = User::where('role', 'admin')->first();
        $newPassword = 'ResetPass123';

        $created = $this->actingAsWithOffice($admin)
            ->postJson('/api/users', [
                'name' => 'Reset Target',
                'email' => 'reset-target@travel.kw',
                'password' => 'InitialPass123',
                'role' => 'auditor',
            ])
            ->assertCreated();

        $this->actingAsWithOffice($admin)
            ->patchJson('/api/users/'.$created->json('id').'/reset-password', [
                'password' => $newPassword,
            ])
            ->assertOk()
            ->assertJsonPath('user.must_change_password', true);

        $user = User::where('email', 'reset-target@travel.kw')->first();
        $this->assertTrue(Hash::check($newPassword, $user->password));

        $this->postJson('/api/login', [
            'email' => 'reset-target@travel.kw',
            'password' => $newPassword,
        ])->assertOk();
    }

    public function test_user_cannot_deactivate_self(): void
    {
        $admin = User::where('role', 'admin')->first();

        $this->actingAsWithOffice($admin)
            ->patchJson("/api/users/{$admin->id}", ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن تعطيل حسابك الشخصي');
    }

    public function test_deactivated_user_is_not_hard_deleted(): void
    {
        $admin = User::where('role', 'admin')->first();

        $created = $this->actingAsWithOffice($admin)
            ->postJson('/api/users', [
                'name' => 'Soft Off User',
                'email' => 'soft-off@travel.kw',
                'password' => 'SecurePass123',
                'role' => 'sales',
            ])
            ->assertCreated();

        $userId = $created->json('id');

        $this->actingAsWithOffice($admin)
            ->patchJson("/api/users/{$userId}", ['is_active' => false])
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'email' => 'soft-off@travel.kw',
            'is_active' => false,
        ]);
    }
}
