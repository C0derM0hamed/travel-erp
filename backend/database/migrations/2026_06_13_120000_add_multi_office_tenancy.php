<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offices', function (Blueprint $table) {
            $table->id();
            $table->string('office_code')->unique();
            $table->string('office_name');
            $table->string('logo')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        $defaultOfficeId = DB::table('offices')->insertGetId([
            'office_code' => 'MAIN',
            'office_name' => 'المكتب الرئيسي',
            'logo' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->addOfficeIdColumns();
        $this->backfillOfficeId($defaultOfficeId);
        $this->addUsersOfficeAndRole($defaultOfficeId);
        $this->dropGlobalUniques();
        $this->addOfficeScopedUniques();
        $this->addTenantIndexes();
        $this->restructureReferenceSequences($defaultOfficeId);
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_sequences');

        Schema::create('reference_sequences', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->unsignedBigInteger('last_value')->default(0);
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'office_id')) {
                $table->dropConstrainedForeignId('office_id');
            }
        });

        foreach (['activity_logs', 'idempotency_keys', 'journal_entries', 'vouchers', 'operations', 'chart_of_accounts', 'safes', 'vendors', 'clients'] as $table) {
            if (Schema::hasColumn($table, 'office_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropConstrainedForeignId('office_id');
                });
            }
        }

        Schema::dropIfExists('offices');
    }

    private function addOfficeIdColumns(): void
    {
        foreach (['clients', 'vendors', 'safes', 'chart_of_accounts', 'operations', 'vouchers', 'journal_entries'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('office_id')->nullable()->after('id')->constrained('offices')->restrictOnDelete();
            });
        }

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->foreignId('office_id')->nullable()->after('id')->constrained('offices')->nullOnDelete();
        });

        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->foreignId('office_id')->nullable()->after('user_id')->constrained('offices')->nullOnDelete();
        });
    }

    private function backfillOfficeId(int $defaultOfficeId): void
    {
        foreach (['clients', 'vendors', 'safes', 'chart_of_accounts', 'operations', 'vouchers'] as $table) {
            DB::table($table)->whereNull('office_id')->update(['office_id' => $defaultOfficeId]);
        }

        foreach (DB::table('journal_entries')->get() as $entry) {
            $officeId = null;

            if ($entry->operation_id) {
                $officeId = DB::table('operations')->where('id', $entry->operation_id)->value('office_id');
            }

            if (! $officeId && $entry->voucher_id) {
                $officeId = DB::table('vouchers')->where('id', $entry->voucher_id)->value('office_id');
            }

            if (! $officeId && $entry->account_id) {
                $officeId = DB::table('chart_of_accounts')->where('id', $entry->account_id)->value('office_id');
            }

            DB::table('journal_entries')->where('id', $entry->id)->update([
                'office_id' => $officeId ?: $defaultOfficeId,
            ]);
        }

        DB::table('activity_logs')->whereNull('office_id')->update(['office_id' => $defaultOfficeId]);
        DB::table('idempotency_keys')->whereNull('office_id')->update(['office_id' => $defaultOfficeId]);
    }

    private function addUsersOfficeAndRole(int $defaultOfficeId): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('office_id')->nullable()->after('role')->constrained('offices')->nullOnDelete();
        });

        DB::table('users')->update(['office_id' => $defaultOfficeId]);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('super_admin', 'admin', 'accountant', 'sales', 'auditor') NOT NULL DEFAULT 'sales'");
        }
    }

    private function dropGlobalUniques(): void
    {
        Schema::table('operations', fn (Blueprint $t) => $t->dropUnique(['ref']));
        Schema::table('vouchers', fn (Blueprint $t) => $t->dropUnique(['ref']));
        Schema::table('chart_of_accounts', function (Blueprint $t) {
            $t->dropUnique(['code']);
            $t->dropUnique(['name']);
        });
        Schema::table('clients', function (Blueprint $t) {
            $t->dropUnique(['phone']);
            $t->dropUnique(['civil_id']);
        });
        Schema::table('vendors', fn (Blueprint $t) => $t->dropUnique(['name']));
    }

    private function addOfficeScopedUniques(): void
    {
        Schema::table('operations', fn (Blueprint $t) => $t->unique(['office_id', 'ref']));
        Schema::table('vouchers', fn (Blueprint $t) => $t->unique(['office_id', 'ref']));
        Schema::table('chart_of_accounts', function (Blueprint $t) {
            $t->unique(['office_id', 'code']);
            $t->unique(['office_id', 'name']);
        });
        Schema::table('clients', function (Blueprint $t) {
            $t->unique(['office_id', 'phone']);
            $t->unique(['office_id', 'civil_id']);
        });
        Schema::table('vendors', fn (Blueprint $t) => $t->unique(['office_id', 'name']));
    }

    private function addTenantIndexes(): void
    {
        Schema::table('operations', function (Blueprint $t) {
            $t->index(['office_id', 'status', 'op_date']);
            $t->index(['office_id', 'client_id', 'status']);
            $t->index(['office_id', 'vendor_id', 'status']);
            $t->index(['office_id', 'service_id', 'status']);
            $t->index(['office_id', 'created_by', 'status']);
        });

        Schema::table('vouchers', function (Blueprint $t) {
            $t->index(['office_id', 'type', 'voucher_date']);
            $t->index(['office_id', 'party_type', 'party_id']);
            $t->index(['office_id', 'operation_id']);
        });

        Schema::table('journal_entries', function (Blueprint $t) {
            $t->index(['office_id', 'entry_date', 'id']);
            $t->index(['office_id', 'account_id', 'party_type', 'party_id']);
            $t->index(['office_id', 'operation_id']);
            $t->index(['office_id', 'voucher_id']);
            $t->index(['office_id', 'ref']);
        });

        Schema::table('safes', fn (Blueprint $t) => $t->index(['office_id', 'type', 'is_active']));
        Schema::table('activity_logs', fn (Blueprint $t) => $t->index(['office_id', 'created_at']));
    }

    private function restructureReferenceSequences(int $defaultOfficeId): void
    {
        $sequences = Schema::hasTable('reference_sequences')
            ? DB::table('reference_sequences')->get()
            : collect();

        Schema::dropIfExists('reference_sequences');

        Schema::create('reference_sequences', function (Blueprint $table) {
            $table->foreignId('office_id')->constrained('offices')->cascadeOnDelete();
            $table->string('key');
            $table->unsignedBigInteger('last_value')->default(0);
            $table->primary(['office_id', 'key']);
        });

        if ($sequences->isEmpty()) {
            foreach (['operation', 'voucher_receipt', 'voucher_payment'] as $key) {
                DB::table('reference_sequences')->insert([
                    'office_id' => $defaultOfficeId,
                    'key' => $key,
                    'last_value' => 0,
                ]);
            }

            return;
        }

        foreach ($sequences as $row) {
            DB::table('reference_sequences')->insert([
                'office_id' => $defaultOfficeId,
                'key' => $row->key,
                'last_value' => $row->last_value,
            ]);
        }
    }
};
