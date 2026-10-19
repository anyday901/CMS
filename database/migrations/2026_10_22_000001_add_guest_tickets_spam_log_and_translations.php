<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A ticket from someone without a client account has their name and email instead.
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->nullable()->change();
            $table->string('guest_name')->nullable()->after('client_id');
            $table->string('guest_email')->nullable()->after('guest_name')->index();
            // The secret in the link that lets a guest see and answer their ticket.
            $table->string('access_key', 64)->nullable()->after('guest_email');
        });

        Schema::table('ticket_messages', function (Blueprint $table) {
            // Written by the guest a ticket belongs to.
            $table->boolean('guest')->default(false)->after('client_id');
        });

        Schema::table('ticket_feedback', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->nullable()->change();
        });

        // Every email piped in for tickets, with what happened to it, so staff can see what the spam filter caught.
        Schema::create('inbound_emails', function (Blueprint $table) {
            $table->id();
            $table->string('from')->nullable();
            $table->string('subject')->nullable();
            // opened, replied, ignored or spam
            $table->string('outcome', 20);
            $table->string('reason')->nullable();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable()->index();
            $table->index(['outcome', 'created_at']);
        });

        // Knowledge base articles and announcements in languages other than the main one.
        Schema::create('content_translations', function (Blueprint $table) {
            $table->id();
            $table->morphs('translatable');
            $table->string('locale', 12);
            $table->string('title');
            $table->longText('body');
            $table->string('tags')->nullable();
            $table->timestamps();
            $table->unique(['translatable_type', 'translatable_id', 'locale'], 'content_translations_translatable_locale_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_translations');
        Schema::dropIfExists('inbound_emails');

        // Earlier versions need a client on every ticket and rating, so guest ones can't stay.
        DB::table('ticket_feedback')->whereNull('client_id')->delete();
        DB::table('tickets')->whereNull('client_id')->delete();
        Schema::table('ticket_feedback', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->nullable(false)->change();
        });

        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->dropColumn('guest');
        });
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['guest_email']);
            $table->dropColumn(['guest_name', 'guest_email', 'access_key']);
            $table->unsignedBigInteger('client_id')->nullable(false)->change();
        });
    }
};
