<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Existing staff keep full access.
            $table->string('role')->default('admin')->after('email');
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            // Who did it: a staff member, a client, or the system (scheduler, webhooks).
            $table->string('actor_type');
            $table->unsignedBigInteger('actor_id')->nullable();
            // Kept as text so the log still reads right after a rename or deletion.
            $table->string('actor_name');
            // The client the entry concerns, for the client's activity tab.
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('subject');
            $table->string('description');
            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('role'));
    }
};
