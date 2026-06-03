<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    use HasFactory;

    protected $fillable = ['ref', 'type', 'party_type', 'party_id', 'amount', 'currency', 'method', 'safe_id', 'operation_id', 'reference_number', 'description', 'voucher_date', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:3', 'voucher_date' => 'date:Y-m-d'];
    }

    public function safe()
    {
        return $this->belongsTo(Safe::class);
    }

    public function operation()
    {
        return $this->belongsTo(Operation::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function journalEntries()
    {
        return $this->hasMany(JournalEntry::class);
    }
}
