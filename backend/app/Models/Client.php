<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use App\Models\Concerns\HasHiddenState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use BelongsToOffice, HasFactory, HasHiddenState;

    protected $fillable = ['office_id', 'name', 'phone', 'alt_phone', 'civil_id', 'email', 'nationality', 'notes', 'is_hidden', 'opening_balance_amount', 'opening_balance_currency_id', 'opening_balance_type'];

    protected function casts(): array
    {
        return [
            'is_hidden' => 'boolean',
            'opening_balance_amount' => 'decimal:3',
        ];
    }

    public function operations()
    {
        return $this->hasMany(Operation::class);
    }
}
