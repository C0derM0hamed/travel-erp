<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use App\Models\Concerns\HasHiddenState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use BelongsToOffice, HasFactory, HasHiddenState;

    protected $fillable = ['office_id', 'name', 'phone', 'alt_phone', 'civil_id', 'email', 'nationality', 'notes', 'is_hidden'];

    protected function casts(): array
    {
        return [
            'is_hidden' => 'boolean',
        ];
    }

    public function operations()
    {
        return $this->hasMany(Operation::class);
    }
}
