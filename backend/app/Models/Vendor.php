<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    use BelongsToOffice, HasFactory;

    protected $fillable = ['office_id', 'name', 'category', 'phone', 'contact', 'address', 'opening_balance_amount', 'opening_balance_currency_id', 'opening_balance_type'];

    protected function casts(): array
    {
        return [
            'opening_balance_amount' => 'decimal:3',
        ];
    }

    public function operations()
    {
        return $this->hasMany(Operation::class);
    }
}
