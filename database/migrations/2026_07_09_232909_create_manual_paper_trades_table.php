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
        Schema::create('manual_paper_trades', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->string('direction'); // LONG | SHORT
            $table->decimal('margin_usdt', 12, 2);
            $table->unsignedInteger('leverage');
            $table->decimal('entry_price', 20, 8);
            $table->decimal('exit_price', 20, 8)->nullable();
            $table->decimal('net_profit_usdt', 12, 4)->nullable();
            $table->string('status')->default('open'); // open | closed
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('manual_paper_trades');
    }
};
