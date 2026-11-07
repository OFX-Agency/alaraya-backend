<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The vehicle statement can now be filtered by car ownership type
     * (office / private), so index the column used by that filter.
     * Additive only: no data is changed.
     */
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->index('type', 'vehicles_type_index');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex('vehicles_type_index');
        });
    }
};
