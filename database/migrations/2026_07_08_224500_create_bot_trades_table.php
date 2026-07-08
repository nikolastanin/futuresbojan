<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_trades', function (Blueprint $table) {
            $table->id();
            $table->uuid('trade_set_id'); // groups main+hedge legs together (hedge lands in a later phase)
            $table->string('leg')->default('main'); // main | hedge
            $table->foreignId('bot_signal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol');
            $table->string('direction'); // LONG | SHORT
            $table->decimal('margin_usd', 10, 2);
            $table->unsignedInteger('leverage');
            $table->decimal('entry_price', 20, 8);
            $table->decimal('exit_price', 20, 8)->nullable();
            $table->decimal('take_profit', 20, 8)->nullable();
            $table->decimal('stop_loss', 20, 8)->nullable();
            $table->decimal('fee_usdt', 10, 4)->nullable();
            $table->decimal('net_profit_usdt', 10, 4)->nullable();
            $table->unsignedTinyInteger('confidence_score');
            $table->json('reason_for_entry')->nullable();
            $table->string('mode'); // paper | real
            $table->string('status')->default('open'); // open | closed
            $table->string('close_reason')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['symbol', 'status']);
            $table->index(['trade_set_id']);
            $table->index(['status', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_trades');
    }
};
