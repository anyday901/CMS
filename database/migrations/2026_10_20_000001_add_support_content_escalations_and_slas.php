<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Knowledge base: categories one level deep, each with Markdown articles.
        Schema::create('kb_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('kb_categories')->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            // Hidden categories and their articles aren't shown to clients.
            $table->boolean('visible')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('kb_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kb_category_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->longText('body');
            // Comma separated words that also match a search.
            $table->string('tags')->nullable();
            $table->boolean('published')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('helpful')->default(0);
            $table->unsignedInteger('not_helpful')->default(0);
            $table->timestamps();
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->longText('body');
            $table->boolean('published')->default(true);
            // Shown from this time, so an announcement can be written ahead.
            $table->timestamp('published_at');
            $table->timestamps();
            $table->index(['published', 'published_at']);
        });

        // Planned work and current problems, for the public network status page.
        Schema::create('network_issues', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description');
            // The server it affects, or what it affects in words ("Email", "Billing system").
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->string('affecting')->nullable();
            $table->string('priority', 20)->default('medium');
            $table->string('status', 20)->default('investigating');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'starts_at']);
        });

        Schema::table('servers', function (Blueprint $table) {
            // Listed with its monitoring status on the network status page.
            $table->boolean('show_status')->default(false)->after('monitor_ports');
        });

        Schema::create('download_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('download_category_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            // On the private disk; always sent as a download.
            $table->string('path');
            $table->string('file_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size');
            // Only logged-in clients can download it.
            $table->boolean('clients_only')->default(false);
            $table->unsignedInteger('downloads')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // A download with products here is only for clients with an active service of one.
        Schema::create('download_product', function (Blueprint $table) {
            $table->foreignId('download_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['download_id', 'product_id']);
        });

        // Ticket custom fields, for every department or one.
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('product_id')->constrained()->cascadeOnDelete();
        });

        Schema::table('departments', function (Blueprint $table) {
            // Hours staff have to answer, by ticket priority, e.g. {"urgent": 1, "high": 4}.
            $table->json('sla_hours')->nullable()->after('active');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->json('custom_fields')->nullable()->after('priority');
            // When staff should answer by, from the department's SLA, while the ticket waits on staff.
            $table->timestamp('response_due_at')->nullable()->after('last_reply_at');
            $table->timestamp('first_response_at')->nullable()->after('response_due_at');
            // Escalation rules already applied since the last reply, so each runs once.
            $table->json('escalations')->nullable()->after('first_response_at');
        });

        Schema::create('escalation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            // Conditions. An empty list matches any.
            $table->json('department_ids')->nullable();
            $table->json('statuses')->nullable();
            $table->json('priorities')->nullable();
            $table->unsignedInteger('minutes_since_reply')->nullable();
            $table->boolean('sla_breached')->default(false);
            // Actions.
            $table->foreignId('set_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('set_status', 20)->nullable();
            $table->string('set_priority', 20)->nullable();
            $table->foreignId('assign_to')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('notify_assignee')->default(false);
            $table->json('notify_user_ids')->nullable();
            $table->text('reply')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escalation_rules');
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['custom_fields', 'response_due_at', 'first_response_at', 'escalations']);
        });
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('sla_hours');
        });
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });
        Schema::dropIfExists('download_product');
        Schema::dropIfExists('downloads');
        Schema::dropIfExists('download_categories');
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('show_status');
        });
        Schema::dropIfExists('network_issues');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('kb_articles');
        Schema::dropIfExists('kb_categories');
    }
};
