<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dedupeClients();
        $this->dedupeVendors();

        Schema::table('clients', function (Blueprint $table) {
            $table->unique('phone');
            $table->unique('civil_id');
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropUnique(['name']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['civil_id']);
            $table->dropUnique(['phone']);
        });
    }

    private function dedupeClients(): void
    {
        $phones = DB::table('clients')
            ->select('phone')
            ->groupBy('phone')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('phone');

        foreach ($phones as $phone) {
            $ids = DB::table('clients')->where('phone', $phone)->orderBy('id')->pluck('id');
            $ids->shift();
            foreach ($ids as $id) {
                DB::table('clients')->where('id', $id)->update(['phone' => $phone.'-dup-'.$id]);
            }
        }

        $civilIds = DB::table('clients')
            ->whereNotNull('civil_id')
            ->where('civil_id', '!=', '')
            ->select('civil_id')
            ->groupBy('civil_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('civil_id');

        foreach ($civilIds as $civilId) {
            $ids = DB::table('clients')->where('civil_id', $civilId)->orderBy('id')->pluck('id');
            $ids->shift();
            foreach ($ids as $id) {
                DB::table('clients')->where('id', $id)->update(['civil_id' => null]);
            }
        }
    }

    private function dedupeVendors(): void
    {
        $names = DB::table('vendors')
            ->select('name')
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('name');

        foreach ($names as $name) {
            $ids = DB::table('vendors')->where('name', $name)->orderBy('id')->pluck('id');
            $ids->shift();
            foreach ($ids as $id) {
                DB::table('vendors')->where('id', $id)->update(['name' => $name.' (dup '.$id.')']);
            }
        }
    }
};
