<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('searches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('text');
            $table->string('hash')->unique();
            $table->vector('embedding', 1536)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('searches');
    }
};
