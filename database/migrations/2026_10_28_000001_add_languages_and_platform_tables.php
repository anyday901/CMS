<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The language a client reads the portal and their emails in; null follows the main language.
        Schema::table('clients', function (Blueprint $table) {
            $table->string('language', 12)->nullable()->after('currency');
        });

        // An email template in a language other than the main one.
        Schema::create('email_template_translations', function (Blueprint $table) {
            $table->id();
            $table->string('template', 64);
            $table->string('locale', 12);
            $table->string('subject');
            $table->text('body');
            $table->timestamps();
            $table->unique(['template', 'locale']);
        });

        // Staff wording for a portal string in one language, over what lang/{locale}.json says.
        Schema::create('translation_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('locale', 12);
            $table->char('key_hash', 64);
            $table->text('key');
            $table->text('value');
            $table->timestamps();
            $table->unique(['locale', 'key_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_overrides');
        Schema::dropIfExists('email_template_translations');
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('language'));
    }
};
