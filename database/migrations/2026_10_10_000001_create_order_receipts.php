<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('receipt_number')->unique();
            $table->uuid('idempotency_key')->unique();
            $table->string('request_hash', 64);
            $table->foreignId('ship_order_id')->constrained('ship_order_data')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('treasury_id')->constrained('treasuries')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->date('receipt_date');
            $table->decimal('total', 15, 2);
            $table->string('status')->default('posted');
            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->unsignedBigInteger('legacy_transport_receipt_id')->nullable()->unique();
            $table->timestamps();
        });
        Schema::create('order_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_receipt_id')->constrained()->restrictOnDelete();
            $table->string('category');
            $table->string('description', 500)->nullable();
            $table->decimal('amount', 15, 2);
            $table->timestamps();
        });
        Schema::table('transport_receipts', function (Blueprint $table) {
            $table->foreignId('treasury_id')->nullable()->constrained('treasuries')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('migrated_to_order_receipt_at')->nullable();
        });
        Schema::table('treasury_deductions', function (Blueprint $table) {
            $table->foreignId('order_receipt_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('transport_receipt_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Never destroy posted financial data through an accidental rollback.
        if (\Illuminate\Support\Facades\DB::table('order_receipts')->exists()
            || \Illuminate\Support\Facades\DB::table('treasury_deductions')->whereNotNull('transport_receipt_id')->exists()) {
            throw new \RuntimeException('Financial receipt data exists. Disable the feature and use a forward migration.');
        }
        Schema::table('treasury_deductions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_receipt_id');
            $table->dropConstrainedForeignId('transport_receipt_id');
        });
        Schema::table('transport_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('treasury_id');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancellation_reason', 'migrated_to_order_receipt_at']);
        });
        Schema::dropIfExists('order_receipt_items');
        Schema::dropIfExists('order_receipts');
    }
};
