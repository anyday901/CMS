<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('company')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->string('phone')->nullable();
            $table->string('address1')->nullable();
            $table->string('address2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postcode')->nullable();
            $table->char('country', 2)->nullable();
            $table->char('currency', 3);
            $table->string('status')->default('active')->index();
            $table->bigInteger('credit_balance')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->string('module')->nullable();
            $table->json('module_config')->nullable();
            $table->timestamps();
        });

        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('billing_cycle');
            $table->char('currency', 3);
            $table->bigInteger('price');
            $table->bigInteger('setup_fee')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'billing_cycle', 'currency']);
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('label')->nullable();
            $table->string('billing_cycle');
            $table->bigInteger('recurring_amount');
            $table->string('status')->default('pending')->index();
            $table->date('registration_date');
            $table->date('next_due_date')->nullable()->index();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->json('custom_fields')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->nullable()->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('unpaid')->index();
            $table->char('currency', 3);
            $table->date('issue_date');
            $table->date('due_date')->index();
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('tax')->default(0);
            $table->bigInteger('total')->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->default('manual');
            $table->string('description');
            $table->bigInteger('amount');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->timestamps();
            $table->index(['service_id', 'period_start']);
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gateway');
            $table->string('gateway_reference')->nullable();
            $table->char('currency', 3);
            // Positive for payments, negative for refunds.
            $table->bigInteger('amount');
            $table->bigInteger('fee')->default(0);
            $table->timestamps();
            $table->unique(['gateway', 'gateway_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('services');
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('products');
        Schema::dropIfExists('clients');
    }
};
