<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_dominance_snapshots', function (Blueprint $table) {
            $table->id();
            $table->decimal('usdt_dominance_pct', 6, 3);
            $table->decimal('btc_dominance_pct', 6, 3);
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_dominance_snapshots');
    }
};
