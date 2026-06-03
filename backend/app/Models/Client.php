<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'phone', 'alt_phone', 'civil_id', 'email', 'nationality', 'notes'];

    public function operations()
    {
        return $this->hasMany(Operation::class);
    }
}
