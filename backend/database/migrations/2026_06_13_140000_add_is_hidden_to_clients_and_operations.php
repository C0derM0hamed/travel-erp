<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clients', 'is_hidden')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->boolean('is_hidden')->default(false)->after('notes')->index();
            });
        }

        if (! Schema::hasColumn('operations', 'is_hidden')) {
            Schema::table('operations', function (Blueprint $table) {
                $table->boolean('is_hidden')->default(false)->after('cancelled_at')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('clients', 'is_hidden')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropColumn('is_hidden');
            });
        }

        if (Schema::hasColumn('operations', 'is_hidden')) {
            Schema::table('operations', function (Blueprint $table) {
                $table->dropColumn('is_hidden');
            });
        }
    }
};
