<?php

namespace App\Console\Commands;

use App\Billing\ExchangeRates;
use App\Billing\PriceConverter;
use Illuminate\Console\Command;
use RuntimeException;

class UpdateExchangeRates extends Command
{
    protected $signature = 'currencies:update-rates
        {--scheduled : Only update when daily updates are on under Settings > Automation}
        {--prices : Then convert every price in the other currencies from the default currency}';

    protected $description = "Update exchange rates from the European Central Bank's daily reference rates";

    public function handle(ExchangeRates $rates, PriceConverter $converter): int
    {
        if ($this->option('scheduled') && ! config('billing.update_exchange_rates')) {
            $this->info('Daily exchange rate updates are off; see Settings > Automation.');

            return self::SUCCESS;
        }

        try {
            $result = $rates->update();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($result['updated'] as $code => $rate) {
            $this->line("{$code}: {$rate}");
        }
        $this->info('Updated '.count($result['updated']).' exchange rate(s)'.($result['date'] ? " from the rates for {$result['date']}." : '.'));
        if ($result['missing'] !== []) {
            $this->warn('No rate published for '.implode(', ', $result['missing']).'; kept the current rate.');
        }

        if ($this->option('prices')) {
            $counts = $converter->run();
            $this->info("Converted {$counts['products']} product, {$counts['options']} option and {$counts['tlds']} TLD price(s).");
        }

        return self::SUCCESS;
    }
}
