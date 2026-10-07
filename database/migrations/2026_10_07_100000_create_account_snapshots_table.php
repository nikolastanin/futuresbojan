<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_snapshots', function (Blueprint $table) {
            $table->id();
            $table->double('equity');
            $table->double('available')->nullable();
            $table->double('position_margin')->nullable();
            $table->double('unrealized')->nullable();
            $table->double('long_notional')->default(0);
            $table->double('short_notional')->default(0);
            $table->unsignedSmallInteger('position_count')->default(0);
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_snapshots');
    }
};
