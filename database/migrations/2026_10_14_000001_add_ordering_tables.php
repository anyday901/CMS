<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('visible')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('product_group_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->boolean('is_addon')->default(false)->after('taxable');
            $table->unsignedInteger('sort_order')->default(0)->after('is_addon');
        });

        // Which add-ons can be ordered with which products.
        Schema::create('product_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('addon_id')->constrained('products')->cascadeOnDelete();
            $table->unique(['product_id', 'addon_id']);
        });

        // Products a service can move to, in either direction.
        Schema::create('product_upgrades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('upgrade_id')->constrained('products')->cascadeOnDelete();
            $table->unique(['product_id', 'upgrade_id']);
        });

        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('type', 10);
            // Hundredths of a percent for percent codes, cents for fixed ones.
            $table->unsignedBigInteger('value');
            $table->boolean('recurring')->default(false);
            $table->json('product_ids')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses')->default(0);
            $table->boolean('once_per_client')->default(false);
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('promotion_id')->nullable()->constrained()->nullOnDelete();
            $table->string('promotion_code')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });

        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            $table->foreignId('parent_service_id')->nullable()->after('order_id')->constrained('services')->nullOnDelete();
            $table->foreignId('promotion_id')->nullable()->after('recurring_amount')->constrained()->nullOnDelete();
            // Taken off each renewal; 0 when the code was for the first payment only.
            $table->bigInteger('recurring_discount')->default(0)->after('promotion_id');
        });

        Schema::create('service_upgrades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('from_product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('to_product_id')->constrained('products')->restrictOnDelete();
            $table->bigInteger('from_amount');
            $table->bigInteger('to_amount');
            $table->json('options')->nullable();
            // Positive: charged on the invoice. Negative: added to credit.
            $table->bigInteger('prorated_amount');
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_upgrades');
        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
            $table->dropConstrainedForeignId('parent_service_id');
            $table->dropConstrainedForeignId('promotion_id');
            $table->dropColumn('recurring_discount');
        });
        Schema::dropIfExists('orders');
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('product_upgrades');
        Schema::dropIfExists('product_addons');
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_group_id');
            $table->dropColumn(['is_addon', 'sort_order']);
        });
        Schema::dropIfExists('product_groups');
    }
};
