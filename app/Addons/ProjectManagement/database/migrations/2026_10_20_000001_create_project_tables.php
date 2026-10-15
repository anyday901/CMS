<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Projects, for a client or internal. A client's projects move when clients are merged.
        Schema::create('pm_projects', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            // Kept (as an internal project) if the client is deleted.
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description')->nullable();
            // planned, active, on_hold, completed or cancelled.
            $table->string('status', 20)->default('active');
            $table->date('due_on')->nullable();
            // Cents an hour, in the client's currency.
            $table->bigInteger('hourly_rate')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'due_on']);
        });

        Schema::create('pm_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('pm_projects')->cascadeOnDelete();
            $table->string('title');
            // Staff only; clients never see these.
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // todo, in_progress or done.
            $table->string('status', 20)->default('todo');
            $table->date('due_on')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'position']);
        });

        Schema::create('pm_time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('pm_projects')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('pm_tasks')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('worked_on');
            $table->unsignedInteger('minutes');
            $table->string('description')->nullable();
            $table->boolean('billable')->default(true);
            // Cents an hour, copied from the project when logged.
            $table->bigInteger('rate')->default(0);
            // The invoice line this time is billed on. Removing the line, or
            // deleting the invoice, makes the time unbilled again.
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_item_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['project_id', 'worked_on']);
        });

        // One running timer per staff member.
        Schema::create('pm_timers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('pm_projects')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('pm_tasks')->nullOnDelete();
            $table->string('description')->nullable();
            $table->timestamp('started_at');
            $table->timestamps();
        });

        // Invoices related to a project: its billed time, and any linked by hand.
        Schema::create('pm_project_invoice', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained('pm_projects')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->primary(['project_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_project_invoice');
        Schema::dropIfExists('pm_timers');
        Schema::dropIfExists('pm_time_entries');
        Schema::dropIfExists('pm_tasks');
        Schema::dropIfExists('pm_projects');
    }
};
