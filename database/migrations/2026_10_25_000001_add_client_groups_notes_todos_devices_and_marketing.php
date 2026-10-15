<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Groups to sort clients into, such as resellers or VIPs.
        Schema::create('client_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('colour', 7)->default('#64748b');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('client_group_id')->nullable()->after('status')->constrained()->nullOnDelete();
            // Whether the client agreed to news and offers, and when they last said yes or no.
            $table->boolean('marketing_emails')->default(false)->after('client_group_id');
            $table->timestamp('marketing_consent_at')->nullable()->after('marketing_emails');
        });

        // Remember-me for contacts, shared by every account one login reaches.
        Schema::table('client_contacts', function (Blueprint $table) {
            $table->rememberToken()->after('password');
        });

        // Staff notes on a client, with pinned ones shown at the top of the client's page.
        Schema::create('client_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->boolean('pinned')->default(false);
            $table->timestamps();
            $table->index(['client_id', 'pinned']);
        });

        // The staff to-do list.
        Schema::create('todos', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('open');
            $table->date('due_on')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'due_on']);
        });

        // Browsers that have signed in to the portal, so a login from a new one can be emailed about.
        Schema::create('known_devices', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('token_hash', 64);
            $table->text('user_agent')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('last_used_at')->nullable()->index();
            $table->timestamp('created_at')->nullable();
            $table->unique(['email', 'token_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('known_devices');
        Schema::dropIfExists('todos');
        Schema::dropIfExists('client_notes');

        Schema::table('client_contacts', fn (Blueprint $table) => $table->dropColumn('remember_token'));

        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_group_id');
            $table->dropColumn(['marketing_emails', 'marketing_consent_at']);
        });

        Schema::dropIfExists('client_groups');
    }
};
