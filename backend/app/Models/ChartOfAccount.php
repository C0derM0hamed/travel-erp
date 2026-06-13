<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChartOfAccount extends Model
{
    use BelongsToOffice, HasFactory;

    protected $fillable = ['office_id', 'code', 'name', 'type', 'safe_id'];

    public function safe()
    {
        return $this->belongsTo(Safe::class);
    }
}
