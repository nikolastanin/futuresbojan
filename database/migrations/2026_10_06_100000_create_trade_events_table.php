<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_events', function (Blueprint $table) {
            $table->id();
            // entry, reduce, close, close_all, sl_tp, lock, unlock, unlock_early, blocked_attempt
            $table->string('type');
            $table->string('symbol');
            $table->string('direction')->nullable(); // LONG / SHORT, of the position acted on
            $table->json('details')->nullable();     // what was done: margin, leverage, hours, prices...
            $table->json('context')->nullable();     // what the analysis said at that moment (entries only)
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index('occurred_at');
            $table->index(['symbol', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_events');
    }
};
