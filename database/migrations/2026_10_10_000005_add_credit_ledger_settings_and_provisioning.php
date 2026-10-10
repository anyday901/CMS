<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every change to a client's credit balance, newest last.
        Schema::create('credit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            $table->string('description');
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['client_id', 'id']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('tax_inclusive')->default(false)->after('tax_rate');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('dispute_status')->nullable()->after('pending');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->string('provisioning_status')->nullable();
            $table->string('provisioning_action')->nullable();
            $table->text('provisioning_error')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            // Whatever the module wants to remember, such as a remote account id.
            $table->json('provisioning_data')->nullable();
            // Actions run strictly in the order they were queued: each job
            // carries a number and waits until the one before it has finished.
            $table->unsignedInteger('provisioning_queued')->default(0);
            $table->unsignedInteger('provisioning_finished')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['provisioning_status', 'provisioning_action', 'provisioning_error', 'provisioned_at', 'provisioning_data', 'provisioning_queued', 'provisioning_finished']);
        });
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('dispute_status');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('tax_inclusive');
        });
        Schema::dropIfExists('settings');
        Schema::dropIfExists('credit_entries');
    }
};
