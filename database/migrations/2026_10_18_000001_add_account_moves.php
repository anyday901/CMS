<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A service's account moving to another server, or to another module
        // after an upgrade: the new account is made first, and the old one,
        // described here, is removed once staff finish the move.
        Schema::create('account_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            // creating, copying, removing, done, failed or cancelled.
            $table->string('status', 20)->index();
            $table->foreignId('from_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('to_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('from_server_id')->nullable()->constrained('servers')->nullOnDelete();
            $table->foreignId('to_server_id')->nullable()->constrained('servers')->nullOnDelete();
            // The old account, as the old module needs it to remove it.
            $table->string('from_module', 50)->nullable();
            $table->string('from_label')->nullable();
            $table->string('from_username')->nullable();
            // Encrypted with the app key.
            $table->text('from_password')->nullable();
            $table->json('from_data')->nullable();
            // Whether there is an old account to remove at all.
            $table->boolean('remove_old')->default(true);
            // Remove the old account as soon as the new one is ready, with nothing to copy.
            $table->boolean('remove_at_once')->default(false);
            $table->text('error')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_moves');
    }
};
