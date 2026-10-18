<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_groups', function (Blueprint $table) {
            // Percent off services in hundredths of a percent (1000 is 10%), as promotion codes keep it.
            $table->unsignedInteger('discount_percent')->default(0)->after('description');
            $table->boolean('discount_renewals')->default(false)->after('discount_percent');
            // Settings that override the global ones for the group's clients.
            $table->boolean('exempt_late_fees')->default(false)->after('discount_renewals');
            $table->boolean('exempt_suspension')->default(false)->after('exempt_late_fees');
            $table->boolean('separate_invoices')->default(false)->after('exempt_suspension');
            // Credit limit for the group's clients without their own, in cents of the default currency.
            $table->unsignedBigInteger('credit_limit')->nullable()->after('separate_invoices');
        });

        // Unpaid invoices a client may run up before overdue suspension, in cents of their currency.
        // Null falls back to their group's limit, then the default in the automation settings.
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedBigInteger('credit_limit')->nullable()->after('credit_balance');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('credit_limit');
        });

        Schema::table('client_groups', function (Blueprint $table) {
            $table->dropColumn(['discount_percent', 'discount_renewals', 'exempt_late_fees', 'exempt_suspension', 'separate_invoices', 'credit_limit']);
        });
    }
};
