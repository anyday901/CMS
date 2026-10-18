<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quotes: a priced proposal for a client, with lines and tax worked out
     * like an invoice's. Accepting one turns it into an unpaid invoice, which
     * invoice_id then points at. Emails that carry a quote PDF remember it.
     */
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->string('number')->nullable()->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('subject');
            // draft, delivered, on_hold, accepted, lost or dead
            $table->string('stage')->default('draft')->index();
            $table->char('currency', 3);
            $table->date('issue_date');
            $table->date('valid_until');
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('tax')->default(0);
            $table->string('tax_name')->nullable();
            $table->unsignedInteger('tax_rate')->default(0);
            $table->boolean('tax_inclusive')->default(false);
            $table->bigInteger('total')->default(0);
            // Shown to the client, on the quote page and the PDF.
            $table->text('notes')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->bigInteger('amount');
            $table->boolean('taxable')->default(true);
            $table->timestamps();
        });

        Schema::table('email_logs', function (Blueprint $table) {
            $table->foreignId('quote_id')->nullable()->after('invoice_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quote_id');
        });
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quotes');
    }
};
