<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The currencies clients can be billed in, each with its exchange rate:
     * how many of it make one of the default currency. The rate is kept as a
     * decimal string so converting prices never goes through floats.
     *
     * Configurable option choices get their prices in the other currencies;
     * their own prices and setup fee stay in the default currency.
     *
     * Existing installs get the billing currency as the default, plus every
     * currency their prices, clients, invoices and payments already use, at a
     * rate of 1 until the rates are updated.
     */
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->char('code', 3)->unique();
            $table->string('rate', 32)->default('1');
            $table->boolean('is_default')->default(false);
            $table->timestamp('rate_updated_at')->nullable();
            $table->timestamps();
        });

        Schema::table('product_option_choices', function (Blueprint $table) {
            $table->json('currency_prices')->nullable()->after('setup_fee');
        });

        $default = strtoupper((string) config('billing.currency', 'USD'));
        $codes = collect([$default]);
        foreach (['product_prices', 'tld_prices', 'clients', 'invoices', 'transactions'] as $source) {
            $codes = $codes->merge(DB::table($source)->whereNotNull('currency')->distinct()->pluck('currency'));
        }

        $now = now();
        DB::table('currencies')->insert($codes->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter(fn (string $code) => preg_match('/^[A-Z]{3}$/', $code) === 1)
            ->unique()
            ->map(fn (string $code) => ['code' => $code, 'rate' => '1', 'is_default' => $code === $default, 'created_at' => $now, 'updated_at' => $now])
            ->values()->all());
    }

    public function down(): void
    {
        Schema::table('product_option_choices', function (Blueprint $table) {
            $table->dropColumn('currency_prices');
        });
        Schema::dropIfExists('currencies');
    }
};
