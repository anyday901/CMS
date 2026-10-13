<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The module copies the old account's data to the new server itself
        // (a cPanel transfer, a Proxmox migration), instead of staff by hand.
        Schema::table('account_moves', function (Blueprint $table) {
            $table->boolean('copy_data')->default(false)->after('remove_at_once');
        });

        // One row per service and calendar month: the latest disk use and the
        // bandwidth used so far that month, in MB, as the server reports them.
        Schema::create('service_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            // The first day of the month.
            $table->date('period');
            $table->unsignedBigInteger('disk_mb')->nullable();
            $table->unsignedBigInteger('disk_limit_mb')->nullable();
            $table->unsignedBigInteger('bandwidth_mb')->nullable();
            $table->unsignedBigInteger('bandwidth_limit_mb')->nullable();
            // For servers that report a traffic counter instead of a monthly
            // total (Proxmox): the last counter read, and the bytes counted
            // this month from the change between readings.
            $table->unsignedBigInteger('counter_bytes')->nullable();
            $table->unsignedBigInteger('counted_bytes')->default(0);
            $table->timestamp('read_at')->nullable();
            // Set once the month's overage has been worked out, with the
            // invoice it went on when there was something to charge.
            $table->timestamp('billed_at')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['service_id', 'period']);
        });

        // Usage-based billing: what a product's services may use each month
        // before overages are charged, and the price per GB over (in cents).
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('overage_billing')->default(false);
            $table->unsignedBigInteger('overage_disk_mb')->nullable();
            $table->unsignedBigInteger('overage_bandwidth_mb')->nullable();
            $table->unsignedInteger('overage_disk_price')->default(0);
            $table->unsignedInteger('overage_bandwidth_price')->default(0);
        });

        // Software licenses issued by the licensing module, one per service.
        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('key', 100)->unique();
            // active, suspended or expired, following the service.
            $table->string('status', 20)->default('active');
            // Where the software may run, locked to the first install when
            // empty. Each is a list; a reissue empties them again.
            $table->json('domains')->nullable();
            $table->json('ips')->nullable();
            $table->json('directories')->nullable();
            $table->unsignedInteger('reissues')->default(0);
            $table->unsignedBigInteger('checks')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_domain')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamps();
        });

        // Server status monitoring.
        Schema::table('servers', function (Blueprint $table) {
            $table->boolean('monitor')->default(true);
            // Other ports to check besides the module's, e.g. "80, 443, 21".
            $table->string('monitor_ports')->nullable();
            // up, warning (a port or node is down) or down (the module's port is).
            $table->string('status', 20)->nullable();
            $table->text('status_detail')->nullable();
            $table->json('status_ports')->nullable();
            $table->json('status_load')->nullable();
            // The status the latest checks found instead, and how many in a
            // row found it; the status changes on the second.
            $table->string('status_pending', 20)->nullable();
            $table->unsignedSmallInteger('status_misses')->default(0);
            $table->timestamp('status_checked_at')->nullable();
            $table->timestamp('status_since')->nullable();
        });

        Schema::create('server_outages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            // warning or down.
            $table->string('status', 20);
            $table->text('detail')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_outages');
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['monitor', 'monitor_ports', 'status', 'status_detail', 'status_ports', 'status_load', 'status_pending', 'status_misses', 'status_checked_at', 'status_since']);
        });
        Schema::dropIfExists('licenses');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['overage_billing', 'overage_disk_mb', 'overage_bandwidth_mb', 'overage_disk_price', 'overage_bandwidth_price']);
        });
        Schema::dropIfExists('service_usages');
        Schema::table('account_moves', function (Blueprint $table) {
            $table->dropColumn('copy_data');
        });
    }
};
