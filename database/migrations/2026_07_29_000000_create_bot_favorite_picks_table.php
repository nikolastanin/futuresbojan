<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_favorite_picks', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->string('direction');
            $table->double('entry_price');
            $table->unsignedTinyInteger('confidence_score');
            $table->unsignedTinyInteger('scalp_grade')->nullable();
            $table->decimal('combined_score', 4, 1);
            $table->decimal('tier_win_rate', 5, 1)->nullable();
            $table->decimal('tier_net_profit_usdt', 10, 4)->nullable();
            $table->unsignedInteger('tier_sample_size')->default(0);
            $table->boolean('is_ai_pick')->default(false);
            $table->text('ai_reasoning')->nullable();
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->index(['computed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_favorite_picks');
    }
};
