<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Machines a provisioning module sets services up on, such as a cPanel server.
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // The key of the module that manages it, e.g. "cpanel".
            $table->string('module', 50)->index();
            $table->string('hostname');
            $table->string('ip_address', 45)->nullable();
            // Addresses the module hands out, one per line (Proxmox VMs).
            $table->text('assigned_ips')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->boolean('secure')->default(true);
            $table->boolean('verify_tls')->default(true);
            $table->string('username')->nullable();
            // Encrypted with the app key.
            $table->text('password')->nullable();
            $table->text('api_token')->nullable();
            $table->unsignedInteger('max_accounts')->default(200);
            // Inactive servers keep their accounts but get no new ones.
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('server_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // least_used or fill.
            $table->string('fill_type', 20)->default('least_used');
            $table->timestamps();
        });

        Schema::create('server_group_server', function (Blueprint $table) {
            $table->foreignId('server_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->primary(['server_group_id', 'server_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('server_group_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            // The account's login on the server; the passwords are encrypted.
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            // A new password staff saved, kept apart until the server has taken it.
            $table->text('new_password')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('server_id');
            $table->dropColumn(['username', 'password', 'new_password']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('server_group_id');
        });
        Schema::dropIfExists('server_group_server');
        Schema::dropIfExists('server_groups');
        Schema::dropIfExists('servers');
    }
};
