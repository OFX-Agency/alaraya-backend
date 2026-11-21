<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deleted policies are kept (soft delete) so their reversed receipts and
 * treasury entries keep a valid policy reference. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('policies', 'deleted_at')) {
            return;
        }
        Schema::table('policies', function (Blueprint $table) {
            $table->softDeletes();
            $table->index('deleted_at', 'policies_deleted_at_index');
        });
    }

    public function down(): void
    {
        // Rolling back would resurrect deleted policies whose receipts were reversed.
        if (\Illuminate\Support\Facades\DB::table('policies')->whereNotNull('deleted_at')->exists()) {
            throw new \RuntimeException('Soft-deleted policies exist; rolling back would restore them.');
        }
        Schema::table('policies', function (Blueprint $table) {
            $table->dropIndex('policies_deleted_at_index');
            $table->dropSoftDeletes();
        });
    }
};
