<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Bill every service of the product on this day of the month (1 to 28).
            $table->unsignedTinyInteger('prorata_day')->nullable()->after('sort_order');
            // Orders on or after this day of the month also pay the next full period.
            $table->unsignedTinyInteger('prorata_extend_day')->nullable()->after('prorata_day');
            $table->unsignedSmallInteger('trial_days')->nullable()->after('prorata_extend_day');
            $table->boolean('stock_control')->default(false)->after('trial_days');
            $table->integer('stock_quantity')->default(0)->after('stock_control');
        });

        Schema::table('services', function (Blueprint $table) {
            // The first day billed after a free trial.
            $table->date('trial_ends_on')->nullable()->after('next_due_date');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->after('invoice_id')->index();
            $table->timestamp('reviewed_at')->nullable()->after('ip_address');
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
        });

        // Orders placed before the queue existed have already been dealt with.
        DB::table('orders')->update(['status' => 'active']);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['status', 'reviewed_at']);
        });
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('trial_ends_on');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['prorata_day', 'prorata_extend_day', 'trial_days', 'stock_control', 'stock_quantity']);
        });
    }
};
