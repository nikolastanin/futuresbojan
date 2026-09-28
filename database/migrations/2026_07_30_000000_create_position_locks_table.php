<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('position_locks', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->unsignedTinyInteger('position_type'); // 1=long, 2=short — matches MEXC's own field
            $table->timestamps();

            $table->unique(['symbol', 'position_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_locks');
    }
};
