<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('key_value_entries', function (Blueprint $table) {
            $table->id();
            $table->string('key')->index();
            $table->unsignedBigInteger('version')->default(1);
            $table->json('value');
            $table->unsignedBigInteger('timestamp');
            $table->timestamps();

            // Composite index for time-travel queries: WHERE key=? AND timestamp <= ? ORDER BY timestamp DESC, version DESC LIMIT 1
            $table->index(['key', 'timestamp', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('key_value_entries');
    }
};
