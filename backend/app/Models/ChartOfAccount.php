<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChartOfAccount extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'type', 'safe_id'];

    public function safe()
    {
        return $this->belongsTo(Safe::class);
    }
}
