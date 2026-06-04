<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Safe extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'type', 'currency', 'opening_balance', 'is_active'];

    protected function casts(): array
    {
        return ['opening_balance' => 'decimal:3', 'is_active' => 'boolean'];
    }

    public function account()
    {
        return $this->hasOne(ChartOfAccount::class);
    }

    public function vouchers()
    {
        return $this->hasMany(Voucher::class);
    }
}
