<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_optimization_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('trade_count');
            $table->timestamp('period_from')->nullable();
            $table->timestamp('period_to')->nullable();
            $table->json('findings');
            $table->json('suggestions');
            $table->timestamp('generated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_optimization_reports');
    }
};
