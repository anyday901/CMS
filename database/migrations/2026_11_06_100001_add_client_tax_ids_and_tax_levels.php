<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // The client's own tax ID or VAT number, printed on their invoices.
            $table->string('tax_id', 100)->nullable()->after('company');
            // When VIES last confirmed it as an EU VAT number; null if it hasn't.
            $table->timestamp('tax_id_verified_at')->nullable()->after('tax_id');
        });

        Schema::table('tax_rules', function (Blueprint $table) {
            // Level 1 or level 2. A client can be taxed by one rule of each level.
            $table->unsignedTinyInteger('level')->default(1)->after('rate');
        });

        foreach (['invoices', 'quotes'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                // Copied when the invoice is created, like tax_name and tax_rate.
                // `tax` stays the total of both levels; tax2 is the level 2 part of it.
                $table->string('tax2_name')->nullable();
                $table->unsignedInteger('tax2_rate')->default(0);
                $table->bigInteger('tax2')->default(0);
                // Level 2 charged on the amount plus level 1 tax.
                $table->boolean('tax2_compound')->default(false);
                // An EU business customer accounts for the VAT themselves.
                $table->boolean('reverse_charge')->default(false);
                $table->string('client_tax_id', 100)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['invoices', 'quotes'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['tax2_name', 'tax2_rate', 'tax2', 'tax2_compound', 'reverse_charge', 'client_tax_id']));
        }
        Schema::table('tax_rules', fn (Blueprint $table) => $table->dropColumn('level'));
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn(['tax_id', 'tax_id_verified_at']));
    }
};
