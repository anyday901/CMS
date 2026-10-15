<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fake_addon_rows', function (Blueprint $table) {
            $table->id();
            $table->string('note');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fake_addon_rows');
    }
};
