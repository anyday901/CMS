<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Credit notes carry the same tax levels, reverse charge and client tax
     * ID as the invoice they credit, so their tax matches what was charged.
     */
    public function up(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            // `tax` stays the total of both levels; tax2 is the level 2 part of it.
            $table->string('tax2_name')->nullable();
            $table->unsignedInteger('tax2_rate')->default(0);
            $table->bigInteger('tax2')->default(0);
            $table->boolean('tax2_compound')->default(false);
            $table->boolean('reverse_charge')->default(false);
            $table->string('client_tax_id', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('credit_notes', fn (Blueprint $table) => $table->dropColumn(['tax2_name', 'tax2_rate', 'tax2', 'tax2_compound', 'reverse_charge', 'client_tax_id']));
    }
};
