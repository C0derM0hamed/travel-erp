<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'role_label', 'avatar'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed'];
    }

    public function canPerform(string $action): bool
    {
        return match ($this->role) {
            'admin' => true,
            'sales' => in_array($action, ['create_op', 'update_op_status'], true),
            'accountant' => in_array($action, ['cancel_op', 'create_voucher', 'update_op_status'], true),
            default => false,
        };
    }
}
