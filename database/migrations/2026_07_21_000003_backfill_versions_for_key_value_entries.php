<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $addedVersionColumn = false;

        if (!Schema::hasColumn('key_value_entries', 'version')) {
            Schema::table('key_value_entries', function (Blueprint $table) {
                $table->unsignedBigInteger('version')->nullable()->after('key');
            });

            $addedVersionColumn = true;
        }

        // Backfill version counters deterministically by key, then write order.
        $rows = DB::table('key_value_entries')
            ->select(['id', 'key'])
            ->orderBy('key')
            ->orderBy('timestamp')
            ->orderBy('id')
            ->get();

        $counters = [];

        foreach ($rows as $row) {
            $next = ($counters[$row->key] ?? 0) + 1;
            $counters[$row->key] = $next;

            DB::table('key_value_entries')
                ->where('id', $row->id)
                ->update(['version' => $next]);
        }

        if ($addedVersionColumn) {
            // Ensure optimized index exists for time-travel + version ordering.
            Schema::table('key_value_entries', function (Blueprint $table) {
                $table->index(['key', 'timestamp', 'version']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally no-op: this migration may run as data-fix only on
        // existing deployments where dropping the index/column could be unsafe.
    }
};
