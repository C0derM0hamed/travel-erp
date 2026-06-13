<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    use BelongsToOffice, HasFactory;

    protected $fillable = ['office_id', 'name', 'category', 'phone', 'contact', 'address'];

    public function operations()
    {
        return $this->hasMany(Operation::class);
    }
}
