<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    use HasFactory;

    protected $fillable = ['entry_date', 'ref', 'source_type', 'source_id', 'operation_id', 'voucher_id', 'account_id', 'party_type', 'party_id', 'party_name', 'debit', 'credit', 'description'];

    protected function casts(): array
    {
        return ['entry_date' => 'date:Y-m-d', 'debit' => 'decimal:3', 'credit' => 'decimal:3'];
    }

    public function account()
    {
        return $this->belongsTo(ChartOfAccount::class);
    }

    public function operation()
    {
        return $this->belongsTo(Operation::class);
    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }
}
