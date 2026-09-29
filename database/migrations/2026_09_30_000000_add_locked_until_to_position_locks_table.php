<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('position_locks', function (Blueprint $table) {
            // Null = indefinite (the original anchor behavior, released manually).
            // Set = a self-expiring timed lock, e.g. "lock for 24 hours" — a patience
            // commitment device, not just an accident guard.
            $table->timestamp('locked_until')->nullable()->after('position_type');
        });
    }

    public function down(): void
    {
        Schema::table('position_locks', function (Blueprint $table) {
            $table->dropColumn('locked_until');
        });
    }
};
