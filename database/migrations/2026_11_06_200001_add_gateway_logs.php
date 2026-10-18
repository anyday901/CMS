<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every callback and webhook a payment gateway sent, and what became of it.
        Schema::create('gateway_logs', function (Blueprint $table) {
            $table->id();
            // The gateway's key, such as stripe, paypal or cashapp.
            $table->string('gateway', 40);
            // The path called, such as webhooks/stripe, and the event type the gateway gave, if any.
            $table->string('endpoint');
            $table->string('event')->nullable();
            // accepted, rejected or error.
            $table->string('outcome', 20);
            $table->unsignedSmallInteger('response_status');
            $table->text('message')->nullable();
            // JSON with secrets and card data redacted.
            $table->longText('payload')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['gateway', 'created_at']);
            $table->index(['outcome', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_logs');
    }
};
