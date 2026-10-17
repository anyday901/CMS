<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The WHMCS tblemails id of an email brought over from WHMCS, so importing
     * the email history again skips the ones already here. Emails are listed
     * by when they were sent, since imported ones can come after newer ones.
     */
    public function up(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('whmcs_id')->nullable()->unique()->after('id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropUnique(['whmcs_id']);
            $table->dropColumn('whmcs_id');
        });
    }
};
