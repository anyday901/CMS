<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Billable items: one-off or recurring charges staff add to a client,
     * which the billing run puts on an invoice. invoice_id is the last
     * invoice one went on, and each invoice line points back at its item,
     * so nothing is billed twice.
     */
    public function up(): void
    {
        Schema::create('billable_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            // Set when the amount is hours at an hourly rate.
            $table->decimal('hours', 8, 2)->nullable();
            $table->bigInteger('rate')->nullable();
            $table->bigInteger('amount');
            $table->boolean('taxable')->default(true);
            // none, next_cron, next_invoice, due_date or recur
            $table->string('invoice_action')->default('none');
            // When it's due; for a recurring item, the next time it's due.
            $table->date('due_date')->nullable();
            $table->unsignedSmallInteger('recur_every')->nullable();
            // day, week, month or year
            $table->string('recur_unit')->nullable();
            // How many times a recurring item is invoiced; null for until stopped.
            $table->unsignedInteger('recur_times')->nullable();
            $table->unsignedInteger('invoiced_count')->default(0);
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['invoice_action', 'due_date']);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('billable_item_id')->nullable()->after('domain_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billable_item_id');
        });
        Schema::dropIfExists('billable_items');
    }
};
