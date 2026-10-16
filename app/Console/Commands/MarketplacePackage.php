<?php

namespace App\Console\Commands;

use App\Marketplace\Manifest;
use App\Marketplace\PackageBuilder;
use Illuminate\Console\Command;
use RuntimeException;

class MarketplacePackage extends Command
{
    protected $signature = 'marketplace:package {path : The add-on or theme folder} {--output= : Where to write the zip (default: {key}-{version}.zip here)}';

    protected $description = 'Zip an add-on or theme for the marketplace and print its SHA-256';

    public function handle(PackageBuilder $builder): int
    {
        $path = rtrim((string) $this->argument('path'), '/');
        $manifest = Manifest::read($path);

        if ($manifest === null) {
            $this->error("{$path} has no valid addon.json or theme.json.");

            return self::FAILURE;
        }

        $output = $this->option('output') ?: getcwd().'/'.$manifest['key'].'-'.$manifest['version'].'.zip';

        try {
            $sha256 = $builder->zip($path, $output);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Wrote {$output}");
        $this->line("sha256: {$sha256}");

        return self::SUCCESS;
    }
}
