<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sequential numbers for paid invoices. With the setting on, a new
     * invoice is a proforma, numbered by its id, until it's paid and takes
     * the next number from the "invoice" sequence. Each sequence is one row,
     * locked while a number is taken, so two payments never share a number
     * and a rolled back payment gives its number back.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('proforma')->default(false)->after('number');
        });

        Schema::create('number_sequences', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->unsignedBigInteger('next')->default(1);
            $table->timestamps();
        });

        DB::table('number_sequences')->insert(['name' => 'invoice', 'next' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('proforma');
        });
    }
};
