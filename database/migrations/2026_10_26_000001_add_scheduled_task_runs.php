<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each time the scheduler ran an automatic task, for the cron status page.
        Schema::create('scheduled_task_runs', function (Blueprint $table) {
            $table->id();
            // The artisan command, such as "billing:run".
            $table->string('task')->index();
            $table->timestamp('started_at')->index();
            $table->timestamp('finished_at')->nullable();
            // running, succeeded, failed or skipped.
            $table->string('status', 20);
            $table->integer('exit_code')->nullable();
            $table->text('error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
    }
};
