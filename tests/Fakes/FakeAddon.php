<?php

namespace Tests\Fakes;

use App\Addons\Addon;
use App\Hooks\HookEvent;
use App\Hooks\Hooks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/** An add-on for tests: one table, one admin page, settings and a hook listener. */
class FakeAddon extends Addon
{
    public static int $booted = 0;

    public function key(): string
    {
        return 'fake';
    }

    public function name(): string
    {
        return 'Fake add-on';
    }

    public function description(): string
    {
        return 'Only for tests.';
    }

    public function version(): string
    {
        return '1.2.0';
    }

    public function settings(): array
    {
        return [
            'greeting' => ['label' => 'Greeting', 'type' => 'text', 'default' => 'Hello', 'rules' => ['max:20']],
            'api_key' => ['label' => 'API key', 'type' => 'secret'],
            'loud' => ['label' => 'Shout', 'type' => 'checkbox'],
            'colour' => ['label' => 'Colour', 'type' => 'select', 'options' => ['red' => 'Red', 'blue' => 'Blue']],
        ];
    }

    public function migrationsPath(): ?string
    {
        return __DIR__.'/addon/migrations';
    }

    public function routes(): void
    {
        $this->adminRoutes('fake', fn () => Route::get('/', fn () => $this->setting('greeting').' from the fake add-on')->name('index'));
    }

    public function adminLinks(): array
    {
        return ['admin.fake.index' => ['Fake', 'manage-settings']];
    }

    public function boot(): void
    {
        self::$booted++;
        app(Hooks::class)->listen('invoice.paid', fn (HookEvent $event) => DB::table('fake_addon_rows')->insert(['note' => "paid {$event->data['id']}"]));
    }
}
