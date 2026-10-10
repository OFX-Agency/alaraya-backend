<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            // SQLite cannot replace foreign keys in-place. BEFORE triggers prevent the old cascades.
            foreach (['policies' => 'policy_id', 'ship_order_data' => 'ship_order_id'] as $parent => $column) {
                \Illuminate\Support\Facades\DB::unprepared("CREATE TRIGGER IF NOT EXISTS protect_receipts_{$parent} BEFORE DELETE ON {$parent} WHEN EXISTS (SELECT 1 FROM transport_receipts WHERE {$column} = OLD.id) BEGIN SELECT RAISE(ABORT, 'Receipt history prevents deletion'); END");
            }

            return;
        }
        Schema::table('transport_receipts', function (Blueprint $table) {
            $table->dropForeign(['ship_order_id']);
            $table->dropForeign(['policy_id']);
            $table->foreign('ship_order_id')->references('id')->on('ship_order_data')->restrictOnDelete();
            $table->foreign('policy_id')->references('id')->on('policies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Keep restrictive foreign keys: restoring cascades would risk financial history.
    }
};
