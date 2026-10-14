<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            // SLA hours count only the business hours in Settings, rather than every hour.
            $table->boolean('sla_business_hours')->default(false)->after('sla_hours');
            // Ask the client to rate the support when staff close a ticket, or it's closed for inactivity.
            $table->boolean('feedback_requests')->default(false)->after('sla_business_hours');
        });

        Schema::table('tickets', function (Blueprint $table) {
            // A merged ticket is closed and its messages moved here; links to it go to this one.
            $table->foreignId('merged_into_id')->nullable()->after('client_id')->constrained('tickets')->nullOnDelete();
            $table->timestamp('closed_at')->nullable()->after('first_response_at');
        });

        Schema::create('ticket_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->timestamps();
        });

        Schema::create('ticket_ticket_tag', function (Blueprint $table) {
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['ticket_id', 'ticket_tag_id']);
        });

        Schema::create('ticket_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_feedback');
        Schema::dropIfExists('ticket_ticket_tag');
        Schema::dropIfExists('ticket_tags');
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_id');
            $table->dropColumn('closed_at');
        });
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn(['sla_business_hours', 'feedback_requests']);
        });
    }
};
