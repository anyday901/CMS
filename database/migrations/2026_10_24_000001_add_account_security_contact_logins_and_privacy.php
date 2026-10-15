<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TWO_FACTOR = ['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_step'];

    public function up(): void
    {
        // Two-factor authentication for clients, contacts and staff. The secret and recovery codes are encrypted.
        foreach (['clients', 'users', 'client_contacts'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->text('two_factor_secret')->nullable();
                $table->text('two_factor_recovery_codes')->nullable();
                $table->timestamp('two_factor_confirmed_at')->nullable();
                // The last time step a code was used for, so a code can't be used twice.
                $table->unsignedBigInteger('two_factor_last_step')->nullable();
            });
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable()->after('email');
            $table->timestamp('anonymised_at')->nullable();
        });

        // Clients from before email verification don't have to verify.
        DB::table('clients')->update(['email_verified_at' => DB::raw('created_at')]);

        // Contacts who can sign in to the portal, with what they can do there.
        Schema::table('client_contacts', function (Blueprint $table) {
            $table->boolean('portal_access')->default(false)->after('support_emails');
            $table->json('permissions')->nullable()->after('portal_access');
            $table->string('password')->nullable()->after('permissions');
            $table->timestamp('last_login_at')->nullable();
            $table->index(['email', 'portal_access']);
        });

        Schema::create('contact_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // Every portal and admin login, failed or not.
        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('area', 10);
            $table->string('email')->nullable();
            $table->nullableMorphs('account');
            $table->foreignId('client_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('outcome', 20);
            $table->timestamp('created_at')->nullable()->index();
            $table->index(['email', 'ip_address', 'created_at']);
            $table->index(['ip_address', 'created_at']);
        });

        Schema::create('ip_bans', function (Blueprint $table) {
            $table->id();
            // One address, or a range such as 203.0.113.0/24.
            $table->string('ip_address', 64)->unique();
            $table->string('reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            // Null for a ban made automatically after too many failed logins.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_bans');
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('contact_password_reset_tokens');

        Schema::table('client_contacts', function (Blueprint $table) {
            $table->dropIndex(['email', 'portal_access']);
            $table->dropColumn(['portal_access', 'permissions', 'password', 'last_login_at']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['email_verified_at', 'anonymised_at']);
        });

        foreach (['clients', 'users', 'client_contacts'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(self::TWO_FACTOR));
        }
    }
};
