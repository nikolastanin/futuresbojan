<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equity_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->double('price');
            $table->double('total_equity');
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['symbol', 'recorded_at']);
            $table->index(['symbol', 'price']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equity_snapshots');
    }
};
