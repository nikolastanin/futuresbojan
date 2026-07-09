<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_optimization_reports', function (Blueprint $table) {
            $table->text('ai_overall_assessment')->nullable()->after('suggestions');
            $table->json('ai_suggestions')->nullable()->after('ai_overall_assessment');
            $table->decimal('ai_estimated_cost_usd', 10, 6)->nullable()->after('ai_suggestions');
        });
    }

    public function down(): void
    {
        Schema::table('bot_optimization_reports', function (Blueprint $table) {
            $table->dropColumn(['ai_overall_assessment', 'ai_suggestions', 'ai_estimated_cost_usd']);
        });
    }
};
