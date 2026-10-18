<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cards and other methods a client saved with a gateway, to pay later
        // without entering them again. Only the gateway's id for the method and
        // what to show (brand, last four digits, expiry) are kept here.
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 40);
            // The gateway's customer the method is attached to, such as a Stripe customer.
            $table->string('gateway_customer_id')->nullable();
            // The gateway's id for the method itself, such as a Stripe PaymentMethod.
            $table->string('gateway_reference');
            $table->string('type', 30)->default('card');
            $table->string('brand', 40)->nullable();
            $table->string('last4', 4)->nullable();
            $table->unsignedTinyInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['gateway', 'gateway_reference']);
        });

        // Each try at charging a saved method for an invoice: by the billing
        // run, by staff, or by the client. Staff see failures on the invoice.
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            // The gateway's id for the charge, e.g. a Stripe PaymentIntent, to match its outcome later.
            $table->string('gateway_reference')->nullable();
            // "Visa ending 4242", kept in case the method is removed later.
            $table->string('method_label');
            $table->string('gateway', 40);
            $table->char('currency', 3);
            $table->bigInteger('amount');
            // succeeded, failed or pending (the gateway is still processing it).
            $table->string('status', 20);
            $table->text('message')->nullable();
            // auto (the billing run) or staff.
            $table->string('source', 20);
            $table->date('attempted_on');
            $table->timestamps();
            $table->index(['invoice_id', 'attempted_on']);
        });

        Schema::table('clients', function (Blueprint $table) {
            // The client's customer id at each gateway, such as {"stripe": "cus_..."}.
            $table->json('gateway_customers')->nullable();
            // Charge the default saved method when invoices fall due. WHMCS
            // calls turning this off "disable automatic CC processing".
            $table->boolean('auto_charge')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['gateway_customers', 'auto_charge']);
        });
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('payment_methods');
    }
};
