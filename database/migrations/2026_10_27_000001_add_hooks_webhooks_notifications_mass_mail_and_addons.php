<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add-on modules installed from the admin area, with their settings.
        Schema::create('addons', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('version', 40);
            $table->boolean('enabled')->default(false);
            // Encrypted JSON, since add-ons can keep API keys here.
            $table->text('settings')->nullable();
            $table->timestamps();
        });

        // Outgoing webhooks: URLs that get a signed POST for the events they subscribe to.
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url', 2000);
            // Encrypted; signs each delivery.
            $table->text('secret');
            $table->json('events');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_id')->constrained()->cascadeOnDelete();
            $table->uuid('event_id');
            $table->string('event');
            $table->json('payload');
            // pending, delivered or failed.
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['webhook_id', 'created_at']);
        });

        // Staff chat channels: Slack, Discord and Microsoft Teams incoming webhooks.
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('name');
            // Encrypted; the incoming webhook URL is the channel's password.
            $table->text('webhook_url');
            $table->json('events');
            $table->boolean('active')->default(true);
            $table->timestamp('last_sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        // Every text message sent, to staff or clients.
        Schema::create('sms_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event')->nullable();
            $table->string('provider', 40);
            $table->string('to', 40);
            $table->text('message');
            // queued, sent or failed.
            $table->string('status', 20);
            $table->string('provider_reference')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::table('clients', function (Blueprint $table) {
            // Clients agree to account texts in the portal.
            $table->boolean('sms_notifications')->default(false)->after('marketing_consent_at');
        });

        // Emails sent to many clients at once.
        Schema::create('mass_mails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject');
            $table->text('body');
            // Marketing emails only go to clients who agreed, with an unsubscribe link.
            $table->boolean('marketing')->default(false);
            $table->json('filters');
            $table->unsignedInteger('recipients')->default(0);
            // queued, sending or sent.
            $table->string('status', 20)->default('queued');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::table('email_logs', function (Blueprint $table) {
            $table->foreignId('mass_mail_id')->nullable()->after('ticket_id')->constrained()->nullOnDelete();
            // The unsubscribe link sent in the List-Unsubscribe header.
            $table->string('list_unsubscribe', 2000)->nullable()->after('reply_to');
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mass_mail_id');
            $table->dropColumn('list_unsubscribe');
        });
        Schema::dropIfExists('mass_mails');
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('sms_notifications'));
        Schema::dropIfExists('sms_log');
        Schema::dropIfExists('notification_channels');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhooks');
        Schema::dropIfExists('addons');
    }
};
