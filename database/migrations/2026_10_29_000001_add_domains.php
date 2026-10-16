<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extensions sold, such as "com" or "co.uk", and the registrar that handles each.
        Schema::create('tlds', function (Blueprint $table) {
            $table->id();
            $table->string('extension', 63)->unique();
            $table->string('registrar')->nullable();
            $table->boolean('active')->default(true);
            // Whether a transfer needs the domain's EPP (auth) code.
            $table->boolean('epp_required')->default(true);
            $table->boolean('id_protection')->default(false);
            $table->boolean('dns_management')->default(false);
            // A port 43 WHOIS server, used when the extension has no RDAP service.
            $table->string('whois_server')->nullable();
            $table->string('whois_available')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // A price for each term in years and currency; a blank price means that term isn't sold.
        Schema::create('tld_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tld_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3);
            $table->unsignedTinyInteger('years');
            $table->bigInteger('register')->nullable();
            $table->bigInteger('transfer')->nullable();
            $table->bigInteger('renew')->nullable();
            $table->bigInteger('id_protection')->default(0);
            $table->timestamps();
            $table->unique(['tld_id', 'currency', 'years']);
        });

        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tld_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->index();
            $table->string('registrar')->nullable();
            // "register" or "transfer": how the domain came to us.
            $table->string('type', 20)->default('register');
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedTinyInteger('years')->default(1);
            // What each renewal for that many years costs, ID protection included.
            $table->bigInteger('recurring_amount')->default(0);
            $table->date('registration_date')->nullable();
            $table->date('expiry_date')->nullable()->index();
            $table->date('next_due_date')->nullable()->index();
            $table->boolean('auto_renew')->default(true);
            $table->boolean('id_protection')->default(false);
            $table->boolean('locked')->nullable();
            $table->json('nameservers')->nullable();
            $table->text('epp_code')->nullable();
            // The registrant contact, when it isn't the client's own details.
            $table->json('contact')->nullable();
            $table->string('registrar_status', 20)->nullable();
            $table->string('registrar_action', 20)->nullable();
            $table->text('registrar_error')->nullable();
            // What the registrar module keeps, such as its order or contact ids.
            $table->json('registrar_data')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('sync_error')->nullable();
            $table->timestamps();
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('domain_id')->nullable()->after('service_id')->constrained()->nullOnDelete();
            $table->index(['domain_id', 'period_start']);
        });

        // Renewal reminders already sent, one per domain, expiry date and number of days.
        Schema::create('domain_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->date('expiry_date');
            // Days before expiry; 0 is the notice sent once it has expired.
            $table->integer('days');
            $table->timestamp('created_at')->nullable();
            $table->unique(['domain_id', 'expiry_date', 'days']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_reminders');
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropIndex(['domain_id', 'period_start']);
            $table->dropConstrainedForeignId('domain_id');
        });
        Schema::dropIfExists('domains');
        Schema::dropIfExists('tld_prices');
        Schema::dropIfExists('tlds');
    }
};
