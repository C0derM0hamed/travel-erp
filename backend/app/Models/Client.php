<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use BelongsToOffice, HasFactory;

    protected $fillable = ['office_id', 'name', 'phone', 'alt_phone', 'civil_id', 'email', 'nationality', 'notes'];

    public function operations()
    {
        return $this->hasMany(Operation::class);
    }
}
