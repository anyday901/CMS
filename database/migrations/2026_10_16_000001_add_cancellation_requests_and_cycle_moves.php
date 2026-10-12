<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cancellation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            // immediate or end_of_period.
            $table->string('type', 20);
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending')->index();
            // The day the service is cancelled on.
            $table->date('cancel_on');
            // Set when staff made the request for the client.
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('service_upgrades', function (Blueprint $table) {
            // Set when the move also changes the billing cycle; the new period starts on the move.
            $table->string('from_cycle', 20)->nullable()->after('to_amount');
            $table->string('to_cycle', 20)->nullable();
            $table->date('next_due_date')->nullable();
            // Set when staff made the move.
            $table->foreignId('moved_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_upgrades', function (Blueprint $table) {
            $table->dropConstrainedForeignId('moved_by');
            $table->dropColumn(['from_cycle', 'to_cycle', 'next_due_date']);
        });
        Schema::dropIfExists('cancellation_requests');
    }
};
