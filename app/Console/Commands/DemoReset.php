<?php

namespace App\Console\Commands;

use App\Support\Demo;
use App\System\Installer;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class DemoReset extends Command
{
    protected $signature = 'demo:reset {--force : Run even when DEMO_MODE is off (it wipes the database)}';

    protected $description = 'Wipe the database and fill it with the demo data (for a public demo instance)';

    public function handle(): int
    {
        if (! Demo::enabled() && ! $this->option('force')) {
            $this->error('DEMO_MODE is off. This wipes the database; pass --force if you really mean it.');

            return self::FAILURE;
        }

        $this->call('migrate:fresh', ['--force' => true, '--seed' => true, '--seeder' => DemoSeeder::class]);
        Storage::disk('local')->deleteDirectory('branding');
        @file_put_contents(app(Installer::class)->marker(), now()->toIso8601String()."\n");
        $this->call('cache:clear');

        $this->info('Demo reset. Staff: '.config('demo.staff_email').' / client: '.config('demo.client_email').', password '.config('demo.password').'.');

        return self::SUCCESS;
    }
}
