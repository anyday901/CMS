<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->string('phone', 50)->nullable();
            $table->boolean('invoice_emails')->default(false);
            $table->boolean('support_emails')->default(false);
            $table->timestamps();
            $table->index('email');
        });

        Schema::table('email_logs', function (Blueprint $table) {
            $table->json('cc')->nullable()->after('to');
        });

        Schema::create('product_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 20);
            $table->unsignedInteger('min_quantity')->default(0);
            $table->unsignedInteger('max_quantity')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('product_option_choices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_option_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('prices')->nullable();
            $table->unsignedBigInteger('setup_fee')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('service_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            // Options and choices a service uses can't be deleted, only renamed.
            $table->foreignId('product_option_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_option_choice_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();
            $table->unique(['service_id', 'product_option_id']);
        });

        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->string('entity', 20);
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 20);
            $table->text('options')->nullable();
            $table->string('help')->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('admin_only')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->json('custom_fields')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('custom_fields');
        });
        Schema::dropIfExists('custom_fields');
        Schema::dropIfExists('service_options');
        Schema::dropIfExists('product_option_choices');
        Schema::dropIfExists('product_options');
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropColumn('cc');
        });
        Schema::dropIfExists('client_contacts');
    }
};
