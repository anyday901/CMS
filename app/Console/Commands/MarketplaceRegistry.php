<?php

namespace App\Console\Commands;

use App\Marketplace\PackageBuilder;
use Illuminate\Console\Command;
use RuntimeException;

class MarketplaceRegistry extends Command
{
    protected $signature = 'marketplace:registry {source : The folder with registry.json and packages/ (usually marketplace)} {output : Where to write registry.json and the zips} {--base-url= : The URL the output folder is published at}';

    protected $description = 'Build the marketplace registry and package zips, ready to publish';

    public function handle(PackageBuilder $builder): int
    {
        $baseUrl = (string) $this->option('base-url');

        if (! str_starts_with($baseUrl, 'https://')) {
            $this->error('Give --base-url, starting with https://, where the output will be published.');

            return self::FAILURE;
        }

        try {
            $packages = $builder->registry(rtrim((string) $this->argument('source'), '/'), rtrim((string) $this->argument('output'), '/'), $baseUrl);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($packages as $package) {
            $this->line("{$package['type']} {$package['key']} {$package['version']}: {$package['sha256']}");
        }
        $this->info('Wrote '.count($packages).' package(s) and registry.json.');

        return self::SUCCESS;
    }
}
