<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_backtests', function (Blueprint $table) {
            $table->id();
            $table->json('symbols');
            $table->timestamp('range_from');
            $table->timestamp('range_to');
            $table->json('config_snapshot');
            $table->string('status')->default('running'); // running | completed | failed
            $table->json('summary')->nullable();
            $table->json('trades')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_backtests');
    }
};
