<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $fillable = ['user_id', 'action', 'subject_type', 'subject_id', 'payload', 'ip'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
