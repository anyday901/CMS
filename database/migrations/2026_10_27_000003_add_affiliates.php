<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clients in the affiliate program, one each, with the code in their referral link.
        Schema::create('affiliates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('code', 40)->unique();
            // active or disabled.
            $table->string('status', 20)->default('active');
            // Null uses the program's commission; otherwise percent (hundredths of a percent) or fixed (minor units).
            $table->string('commission_type', 20)->nullable();
            $table->unsignedInteger('commission_value')->nullable();
            $table->unsignedInteger('visits')->default(0);
            $table->timestamps();
        });

        // Who referred a client. A client has one referrer at most.
        Schema::create('affiliate_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        // Payouts an affiliate asked for, paid as account credit or outside the app.
        Schema::create('affiliate_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->integer('amount');
            $table->char('currency', 3);
            // requested, paid or rejected.
            $table->string('status', 20)->default('requested');
            // credit or manual, once paid.
            $table->string('method', 20)->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        // What an affiliate earned on each paid invoice of a client they referred.
        Schema::create('affiliate_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('referral_id')->nullable()->constrained('affiliate_referrals')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('description')->nullable();
            $table->integer('amount');
            // Before any refund lowered it, so later refunds are worked out from the same starting point.
            $table->integer('original_amount');
            $table->char('currency', 3);
            // pending, approved, paid or void.
            $table->string('status', 20)->default('pending');
            $table->timestamp('available_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->foreignId('payout_id')->nullable()->constrained('affiliate_payouts')->nullOnDelete();
            $table->timestamps();
            $table->index(['affiliate_id', 'status']);
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_commissions');
        Schema::dropIfExists('affiliate_payouts');
        Schema::dropIfExists('affiliate_referrals');
        Schema::dropIfExists('affiliates');
    }
};
