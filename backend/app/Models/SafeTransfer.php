<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SafeTransfer extends Model
{
    use BelongsToOffice, HasFactory;

    protected $fillable = [
        'office_id', 'ref', 'from_safe_id', 'to_safe_id', 'amount', 'currency',
        'transfer_date', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'transfer_date' => 'date:Y-m-d',
        ];
    }

    public function fromSafe()
    {
        return $this->belongsTo(Safe::class, 'from_safe_id');
    }

    public function toSafe()
    {
        return $this->belongsTo(Safe::class, 'to_safe_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
