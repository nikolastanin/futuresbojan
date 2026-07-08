<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_universe_scans', function (Blueprint $table) {
            $table->id();
            $table->uuid('scan_id'); // groups all pairs from a single scan run
            $table->string('symbol');
            $table->boolean('included');
            $table->unsignedTinyInteger('market_quality_score')->nullable();
            $table->decimal('volume_24h_usdt', 20, 2)->nullable();
            $table->decimal('atr', 20, 8)->nullable();
            $table->string('exclusion_reason')->nullable();
            $table->timestamp('scanned_at');
            $table->timestamps();

            $table->index(['scan_id']);
            $table->index(['symbol', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_universe_scans');
    }
};
