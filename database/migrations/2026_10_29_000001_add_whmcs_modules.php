<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // WHMCS server modules uploaded under Settings > WHMCS modules, run through a compatibility layer.
        Schema::create('whmcs_modules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('display_name');
            $table->boolean('enabled')->default(false);
            $table->boolean('needs_domain')->default(true);
            $table->json('meta')->nullable();
            $table->json('report');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whmcs_modules');
    }
};
