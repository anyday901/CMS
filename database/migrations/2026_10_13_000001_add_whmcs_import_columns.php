<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // A password hash brought over from WHMCS. Checked once at login,
            // then replaced by a fresh hash in "password".
            $table->string('legacy_password')->nullable()->after('password');
        });

        Schema::table('tickets', function (Blueprint $table) {
            // The ticket number WHMCS showed clients, such as "482913", so
            // replies to old ticket emails still find the ticket.
            $table->string('whmcs_tid', 32)->nullable()->index()->after('id');
        });

        Schema::table('ticket_messages', function (Blueprint $table) {
            // Who wrote an imported message, when they have no account here.
            $table->string('author_name')->nullable()->after('client_id');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_messages', fn (Blueprint $table) => $table->dropColumn('author_name'));
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['whmcs_tid']);
            $table->dropColumn('whmcs_tid');
        });
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('legacy_password'));
    }
};
