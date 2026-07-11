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
        Schema::table('manual_paper_trades', function (Blueprint $table) {
            $table->decimal('stop_loss', 20, 8)->nullable()->after('entry_price');
            $table->decimal('take_profit', 20, 8)->nullable()->after('stop_loss');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('manual_paper_trades', function (Blueprint $table) {
            $table->dropColumn(['stop_loss', 'take_profit']);
        });
    }
};
