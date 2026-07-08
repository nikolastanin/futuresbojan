<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_trades', function (Blueprint $table) {
            // Exact contract quantity from the real MEXC fill — needed to close the exact
            // size later. Null for paper trades (nominal/margin is enough for simulation).
            $table->decimal('contract_vol', 20, 4)->nullable()->after('leverage');
            $table->string('order_id')->nullable()->after('contract_vol');
        });
    }

    public function down(): void
    {
        Schema::table('bot_trades', function (Blueprint $table) {
            $table->dropColumn(['contract_vol', 'order_id']);
        });
    }
};
