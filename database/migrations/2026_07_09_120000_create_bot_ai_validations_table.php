<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_ai_validations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_signal_id')->constrained()->cascadeOnDelete();
            $table->string('symbol');
            $table->unsignedTinyInteger('original_confidence_score');
            $table->unsignedTinyInteger('final_confidence_score');
            $table->string('verdict'); // confirm, reduce, veto, error (fail-open)
            $table->text('reasoning')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('estimated_cost_usd', 10, 6)->default(0);
            $table->timestamps();

            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_ai_validations');
    }
};
