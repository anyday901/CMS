<?php

namespace App\Support;

use App\Models\TranslationOverride;
use Illuminate\Database\QueryException;
use Illuminate\Translation\FileLoader;
use PDOException;

/**
 * Laravel's translation files, with staff wording from Settings > Portal
 * text laid over the JSON strings of each language.
 */
class TranslationLoader extends FileLoader
{
    public function load($locale, $group, $namespace = null)
    {
        $lines = parent::load($locale, $group, $namespace);

        if ($group !== '*' || $namespace !== '*') {
            return $lines;
        }

        try {
            $overrides = TranslationOverride::where('locale', $locale)->pluck('value', 'key')->all();
        } catch (QueryException|PDOException) {
            // Not installed or migrated yet.
            return $lines;
        }

        return array_merge($lines, $overrides);
    }

    /**
     * Every string in the catalogue: lang/en.json and the English JSON of add-ons.
     *
     * @return list<string>
     */
    public function catalogue(): array
    {
        $keys = [];

        foreach ([$this->paths[1] ?? lang_path(), ...$this->jsonPaths] as $path) {
            $file = $path.'/en.json';
            if (is_file($file)) {
                $keys = [...$keys, ...array_keys((array) json_decode((string) file_get_contents($file), true))];
            }
        }

        $keys = array_values(array_unique(array_map('strval', $keys)));
        sort($keys, SORT_NATURAL | SORT_FLAG_CASE);

        return $keys;
    }

    /**
     * What the files say for a language, without staff overrides.
     *
     * @return array<string, string>
     */
    public function shipped(string $locale): array
    {
        return parent::load($locale, '*', '*');
    }
}
