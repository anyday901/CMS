<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Credit notes: money given back against a paid or part paid invoice,
     * for some or all of its lines, with the invoice's tax worked out the
     * same way. Each one is numbered from the "credit_note" sequence, and the
     * refunds it made point back at it.
     */
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->char('currency', 3);
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('tax')->default(0);
            $table->string('tax_name')->nullable();
            $table->unsignedInteger('tax_rate')->default(0);
            $table->boolean('tax_inclusive')->default(false);
            $table->bigInteger('total')->default(0);
            // Shown to the client, on the PDF.
            $table->text('reason');
            // credit (to the client's credit balance), gateway or manual
            $table->string('refund_mode');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Set when its gateway refund failed after being accepted, so it no longer counts.
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('credit_note_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_note_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->bigInteger('amount');
            $table->boolean('taxable')->default(true);
            $table->timestamps();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('credit_note_id')->nullable()->after('refund_of_id')->constrained()->nullOnDelete();
        });

        DB::table('number_sequences')->insert(['name' => 'credit_note', 'next' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('number_sequences')->where('name', 'credit_note')->delete();
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('credit_note_id');
        });
        Schema::dropIfExists('credit_note_items');
        Schema::dropIfExists('credit_notes');
    }
};
