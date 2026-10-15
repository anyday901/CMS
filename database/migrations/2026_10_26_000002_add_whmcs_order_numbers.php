<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The order number WHMCS showed clients, which differs from its order id. */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('whmcs_number', 20)->nullable()->after('id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['whmcs_number']);
            $table->dropColumn('whmcs_number');
        });
    }
};
