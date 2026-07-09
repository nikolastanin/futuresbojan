<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_trades', function (Blueprint $table) {
            $table->timestamp('breakeven_profit_since')->nullable()->after('peak_net_profit_usdt');
            $table->boolean('breakeven_applied')->default(false)->after('breakeven_profit_since');
        });
    }

    public function down(): void
    {
        Schema::table('bot_trades', function (Blueprint $table) {
            $table->dropColumn(['breakeven_profit_since', 'breakeven_applied']);
        });
    }
};
