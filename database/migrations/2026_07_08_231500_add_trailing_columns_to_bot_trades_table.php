<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_trades', function (Blueprint $table) {
            $table->boolean('trailing_active')->default(false)->after('stop_loss');
            $table->decimal('peak_net_profit_usdt', 10, 4)->nullable()->after('trailing_active');
        });
    }

    public function down(): void
    {
        Schema::table('bot_trades', function (Blueprint $table) {
            $table->dropColumn(['trailing_active', 'peak_net_profit_usdt']);
        });
    }
};
