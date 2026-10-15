<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keys for the REST API. Only a SHA-256 hash of each key is kept; staff see the key once.
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // The start of the key, shown in the list so staff can tell keys apart.
            $table->string('prefix', 20)->unique();
            $table->char('secret_hash', 64);
            $table->json('abilities');
            // IP addresses or CIDR ranges the key works from; null means anywhere.
            $table->json('allowed_ips')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            // The staff member who made it. Staff replies sent with the key are in their name.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
