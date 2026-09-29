<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The automated trading bot (risk management, order placement, trade lifecycle,
 * universe scanning, AI validation/advisor, optimization) has been removed —
 * drops every table that only existed to support it, including historical trade
 * data (a deliberate choice, not an oversight: the user asked for a clean slate
 * rather than keeping a read-only record). bot_dominance_snapshots is NOT dropped —
 * despite the name, it backs DominanceService's macro trend overlay, which is
 * shared manual-dashboard infrastructure (feeds the live signal preview), not
 * bot-trading-specific.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('bot_ai_validations');
        Schema::dropIfExists('bot_optimization_reports');
        Schema::dropIfExists('bot_backtests');
        Schema::dropIfExists('bot_trades');
        Schema::dropIfExists('bot_signals');
        Schema::dropIfExists('bot_universe_scans');
        Schema::dropIfExists('bot_logs');
        Schema::dropIfExists('bot_settings');
    }

    public function down(): void
    {
        // Deliberately irreversible — the automated bot code these tables supported
        // is gone, and the data was intentionally dropped, not archived.
    }
};
