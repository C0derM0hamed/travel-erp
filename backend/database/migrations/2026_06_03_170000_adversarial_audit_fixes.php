<?php

use App\Support\PhoneNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_sequences', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->unsignedBigInteger('last_value')->default(0);
        });

        $this->seedReferenceSequences();
        $this->normalizeClientPhones();
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_sequences');
    }

    private function seedReferenceSequences(): void
    {
        $opMax = (int) DB::table('operations')->max('id');
        $voucherMax = (int) DB::table('vouchers')->max('id');

        DB::table('reference_sequences')->insert([
            ['key' => 'operation', 'last_value' => max($opMax, $this->maxRefNumber('operations', 'OP-'))],
            ['key' => 'voucher_receipt', 'last_value' => max($voucherMax, $this->maxRefNumber('vouchers', 'RV-'))],
            ['key' => 'voucher_payment', 'last_value' => max($voucherMax, $this->maxRefNumber('vouchers', 'PV-'))],
        ]);
    }

    private function maxRefNumber(string $table, string $prefix): int
    {
        $max = 0;
        foreach (DB::table($table)->pluck('ref') as $ref) {
            if (is_string($ref) && str_starts_with($ref, $prefix)) {
                $num = (int) substr($ref, strlen($prefix));
                $max = max($max, $num);
            }
        }

        return $max;
    }

    private function normalizeClientPhones(): void
    {
        foreach (DB::table('clients')->orderBy('id')->get() as $client) {
            $normalized = PhoneNormalizer::normalize($client->phone);
            if ($normalized === null) {
                continue;
            }

            $conflict = DB::table('clients')
                ->where('phone', $normalized)
                ->where('id', '!=', $client->id)
                ->exists();

            if ($conflict) {
                DB::table('clients')->where('id', $client->id)->update([
                    'phone' => $normalized.'-dup-'.$client->id,
                ]);

                continue;
            }

            if ($normalized !== $client->phone) {
                DB::table('clients')->where('id', $client->id)->update(['phone' => $normalized]);
            }

            if ($client->alt_phone) {
                $alt = PhoneNormalizer::normalize($client->alt_phone);
                if ($alt !== null && $alt !== $client->alt_phone) {
                    DB::table('clients')->where('id', $client->id)->update(['alt_phone' => $alt]);
                }
            }
        }
    }
};
