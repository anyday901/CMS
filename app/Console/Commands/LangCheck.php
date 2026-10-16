<?php

namespace App\Console\Commands;

use App\Mail\EmailTemplates;
use App\Support\Language;
use Illuminate\Console\Command;

class LangCheck extends Command
{
    protected $signature = 'lang:check {locale? : One language code; leave out for every lang/*.json}';

    protected $description = 'List portal strings and email templates missing from each translation';

    public function handle(): int
    {
        $catalogue = app('translation.loader')->catalogue();
        $locales = $this->argument('locale') ? [(string) $this->argument('locale')] : array_values(array_diff(Language::shipped(), ['en']));
        $incomplete = false;

        foreach ($locales as $locale) {
            $file = lang_path($locale.'.json');
            $lines = is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
            $missing = array_values(array_diff($catalogue, array_keys($lines)));
            $extra = array_values(array_diff(array_keys($lines), $catalogue));
            $emails = array_values(array_filter(array_keys(EmailTemplates::definitions()), fn (string $key) => EmailTemplates::shipped($key, $locale) === null));
            $placeholders = array_values(array_filter(array_keys($lines), fn ($key) => self::placeholders((string) $key) !== self::placeholders((string) $lines[$key])));

            $this->line(sprintf('%s: %d of %d strings, %d of %d emails', Language::name($locale), count($catalogue) - count($missing), count($catalogue), count(EmailTemplates::definitions()) - count($emails), count(EmailTemplates::definitions())));

            foreach ([['Missing', $missing], ['Not in lang/en.json', $extra], ['Placeholders differ', $placeholders], ['Missing emails', $emails]] as [$label, $keys]) {
                foreach ($keys as $key) {
                    $this->line("  {$label}: {$key}");
                }
            }

            $incomplete = $incomplete || $missing !== [] || $placeholders !== [] || $emails !== [];
        }

        return $incomplete ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<string> the :placeholders in a string, sorted */
    private static function placeholders(string $text): array
    {
        preg_match_all('/:([a-z_]+)/i', $text, $matches);
        $names = array_unique($matches[1]);
        sort($names);

        return $names;
    }
}
