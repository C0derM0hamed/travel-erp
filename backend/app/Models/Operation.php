<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Operation extends Model
{
    use HasFactory;

    protected $fillable = ['ref', 'client_id', 'service_id', 'vendor_id', 'currency', 'client_price', 'vendor_cost', 'profit', 'initial_payment', 'payment_method', 'notes', 'status', 'created_by', 'op_date', 'cancelled_at'];

    protected function casts(): array
    {
        return [
            'client_price' => 'decimal:3',
            'vendor_cost' => 'decimal:3',
            'profit' => 'decimal:3',
            'initial_payment' => 'decimal:3',
            'op_date' => 'date:Y-m-d',
            'cancelled_at' => 'datetime',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function vouchers()
    {
        return $this->hasMany(Voucher::class);
    }

    public function journalEntries()
    {
        return $this->hasMany(JournalEntry::class);
    }
}
