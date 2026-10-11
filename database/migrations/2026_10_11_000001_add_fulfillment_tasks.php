<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Work staff do by hand for products on the manual fulfillment module.
        Schema::create('fulfillment_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('action');
            // The provisioning operation that opened it; a retry of the same one reuses the task.
            $table->unsignedInteger('operation');
            // [{"text": "Create the account", "done": false}, ...]
            $table->json('checklist');
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('services', function (Blueprint $table) {
            // Identifies the last action that ran, so running a failed action
            // again tells the module it is the same action, not a new one.
            $table->unsignedInteger('provisioning_operation')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('provisioning_operation');
        });
        Schema::dropIfExists('fulfillment_tasks');
    }
};
