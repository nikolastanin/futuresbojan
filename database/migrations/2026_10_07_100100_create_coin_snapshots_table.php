<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coin_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->double('price');
            $table->double('funding_rate')->nullable();   // per funding interval, as MEXC reports it (0.0001 = 0.01%)
            $table->double('open_interest')->nullable();  // MEXC's holdVol, in contracts
            $table->double('long_notional')->default(0);  // the trader's own exposure in this coin
            $table->double('short_notional')->default(0);
            $table->double('combined_pnl')->nullable();   // null when no position is open in the coin
            $table->double('nearest_liq_pct')->nullable(); // distance from the mark to the closest liquidation price
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['symbol', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coin_snapshots');
    }
};
