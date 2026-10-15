<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Knowledge base categories are translated too: a name, and a description only when there is one.
        Schema::table('content_translations', function (Blueprint $table) {
            $table->longText('body')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('content_translations')->whereNull('body')->update(['body' => '']);
        Schema::table('content_translations', function (Blueprint $table) {
            $table->longText('body')->nullable(false)->change();
        });
    }
};
