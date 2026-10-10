<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Hundredths of a percent: 825 is 8.25%.
            $table->unsignedInteger('rate');
            // Null matches every country or state.
            $table->char('country', 2)->nullable();
            $table->string('state')->nullable();
            $table->timestamps();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('tax_exempt')->default(false)->after('currency');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('taxable')->default(true)->after('active');
        });

        Schema::table('invoices', function (Blueprint $table) {
            // Copied from the matching tax rule when the invoice is created,
            // so later rule changes don't rewrite old invoices.
            $table->string('tax_name')->nullable()->after('subtotal');
            $table->unsignedInteger('tax_rate')->default(0)->after('tax_name');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->boolean('taxable')->default(true)->after('amount');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('refund_of_id')->nullable()->after('invoice_id')
                ->constrained('transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refund_of_id');
        });
        Schema::table('invoice_items', fn (Blueprint $table) => $table->dropColumn('taxable'));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['tax_name', 'tax_rate']));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('taxable'));
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('tax_exempt'));
        Schema::dropIfExists('tax_rules');
    }
};
