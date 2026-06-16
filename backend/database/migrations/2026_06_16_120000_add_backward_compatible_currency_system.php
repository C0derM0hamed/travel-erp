<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->unique();
            $table->string('name');
            $table->string('symbol', 16);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('currencies')->insert([
            ['code' => 'KWD', 'name' => 'دينار كويتي', 'symbol' => 'د.ك', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'USD', 'name' => 'دولار أمريكي', 'symbol' => '$', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'SAR', 'name' => 'ريال سعودي', 'symbol' => 'ر.س', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'EUR', 'name' => 'يورو', 'symbol' => '€', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $kwdId = (int) DB::table('currencies')->where('code', 'KWD')->value('id');

        DB::table('app_settings')->insert([
            'key' => 'default_currency_id',
            'value' => (string) $kwdId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Schema::table('offices', function (Blueprint $table) {
            $table->foreignId('default_currency_id')->nullable()->after('is_active')->constrained('currencies')->restrictOnDelete();
        });
        DB::table('offices')->whereNull('default_currency_id')->update(['default_currency_id' => $kwdId]);

        foreach (['operations', 'vouchers', 'safes', 'safe_transfers', 'journal_entries'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'currency')) {
                    $after = $tableName === 'journal_entries' ? 'credit' : 'amount';
                    $table->string('currency', 3)->nullable()->after($after);
                }
                if (! Schema::hasColumn($tableName, 'currency_id')) {
                    $table->foreignId('currency_id')->nullable()->after('currency')->constrained('currencies')->restrictOnDelete();
                }
            });
        }

        $this->backfillCurrencyMetadata($kwdId);
    }

    public function down(): void
    {
        foreach (['journal_entries', 'safe_transfers', 'safes', 'vouchers', 'operations'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'currency_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('currency_id');
                });
            }
        }

        if (Schema::hasTable('offices') && Schema::hasColumn('offices', 'default_currency_id')) {
            Schema::table('offices', function (Blueprint $table) {
                $table->dropConstrainedForeignId('default_currency_id');
            });
        }

        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('currencies');
    }

    private function backfillCurrencyMetadata(int $fallbackCurrencyId): void
    {
        foreach (['operations', 'vouchers', 'safes', 'safe_transfers'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            DB::table($tableName)
                ->whereNull('currency')
                ->update(['currency' => 'KWD']);

            foreach (DB::table('currencies')->select(['id', 'code'])->get() as $currency) {
                DB::table($tableName)
                    ->where('currency', $currency->code)
                    ->whereNull('currency_id')
                    ->update(['currency_id' => $currency->id]);
            }

            DB::table($tableName)
                ->whereNull('currency_id')
                ->update(['currency_id' => $fallbackCurrencyId, 'currency' => 'KWD']);
        }

        if (! Schema::hasTable('journal_entries')) {
            return;
        }

        foreach (DB::table('journal_entries')->orderBy('id')->get() as $entry) {
            $currencyId = null;
            $currencyCode = null;

            if ($entry->operation_id) {
                $currencyCode = DB::table('operations')->where('id', $entry->operation_id)->value('currency');
                $currencyId = DB::table('operations')->where('id', $entry->operation_id)->value('currency_id');
            } elseif ($entry->voucher_id) {
                $currencyCode = DB::table('vouchers')->where('id', $entry->voucher_id)->value('currency');
                $currencyId = DB::table('vouchers')->where('id', $entry->voucher_id)->value('currency_id');
            } elseif ($entry->source_type === 'transfer') {
                $currencyCode = DB::table('safe_transfers')->where('id', $entry->source_id)->value('currency');
                $currencyId = DB::table('safe_transfers')->where('id', $entry->source_id)->value('currency_id');
            }

            if (! $currencyCode) {
                $currencyCode = 'KWD';
            }
            if (! $currencyId) {
                $currencyId = $fallbackCurrencyId;
            }

            DB::table('journal_entries')->where('id', $entry->id)->update([
                'currency' => $currencyCode,
                'currency_id' => $currencyId,
            ]);
        }
    }
};
