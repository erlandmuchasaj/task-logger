<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// M7 (docs/09-scalability.md): the all-people log list and month exports sort and filter by date alone.
// A wide covering index for the report was tried and removed: MySQL chose a worse plan (5.4 s at 1.9M rows).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logs', function (Blueprint $table) {
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::table('logs', function (Blueprint $table) {
            $table->dropIndex(['date']);
        });
    }
};
