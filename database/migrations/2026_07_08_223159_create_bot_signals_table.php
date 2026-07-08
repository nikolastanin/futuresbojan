<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_signals', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->string('direction')->nullable(); // LONG, SHORT, or null (no signal)
            $table->unsignedTinyInteger('confidence_score');
            $table->json('reasons'); // per-factor breakdown, human readable
            $table->decimal('entry_price', 20, 8)->nullable();
            $table->decimal('take_profit', 20, 8)->nullable();
            $table->decimal('stop_loss', 20, 8)->nullable();
            $table->decimal('estimated_fee_usdt', 10, 4)->nullable();
            $table->decimal('expected_net_profit_usdt', 10, 4)->nullable();
            $table->boolean('opened')->default(false);
            $table->string('skip_reason')->nullable();
            $table->timestamp('analyzed_at');
            $table->timestamps();

            $table->index(['symbol', 'analyzed_at']);
            $table->index(['analyzed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_signals');
    }
};
