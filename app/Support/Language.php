<?php

namespace App\Support;

use App\Models\Client;

/**
 * The languages the portal, client emails, knowledge base articles and
 * announcements are offered in: the main one, and the others they can be
 * translated into (both under Settings), and which one a visitor sees.
 */
final class Language
{
    /** Session key holding the language a visitor picked. */
    public const SESSION_KEY = 'help_language';

    /** Names of common languages, by code. Other codes show as the code. */
    public const NAMES = [
        'ar' => 'Arabic', 'az' => 'Azerbaijani', 'bg' => 'Bulgarian', 'ca' => 'Catalan', 'cs' => 'Czech', 'da' => 'Danish', 'de' => 'German',
        'el' => 'Greek', 'en' => 'English', 'es' => 'Spanish', 'et' => 'Estonian', 'fa' => 'Persian', 'fi' => 'Finnish', 'fr' => 'French',
        'he' => 'Hebrew', 'hr' => 'Croatian', 'hu' => 'Hungarian', 'id' => 'Indonesian', 'it' => 'Italian', 'ja' => 'Japanese', 'ko' => 'Korean',
        'lt' => 'Lithuanian', 'lv' => 'Latvian', 'mk' => 'Macedonian', 'nb' => 'Norwegian', 'nl' => 'Dutch', 'pl' => 'Polish',
        'pt' => 'Portuguese', 'pt-BR' => 'Portuguese (Brazil)', 'pt-PT' => 'Portuguese (Portugal)', 'ro' => 'Romanian', 'ru' => 'Russian',
        'sk' => 'Slovak', 'sl' => 'Slovenian', 'sr' => 'Serbian', 'sv' => 'Swedish', 'th' => 'Thai', 'tr' => 'Turkish', 'uk' => 'Ukrainian',
        'vi' => 'Vietnamese', 'zh' => 'Chinese', 'zh-TW' => 'Chinese (Traditional)',
    ];

    /** WHMCS names its languages after its language files. */
    private const WHMCS = [
        'arabic' => 'ar', 'azerbaijani' => 'az', 'bulgarian' => 'bg', 'catalan' => 'ca', 'chinese' => 'zh', 'croatian' => 'hr', 'czech' => 'cs',
        'danish' => 'da', 'dutch' => 'nl', 'english' => 'en', 'estonian' => 'et', 'farsi' => 'fa', 'finnish' => 'fi', 'french' => 'fr',
        'german' => 'de', 'greek' => 'el', 'hebrew' => 'he', 'hungarian' => 'hu', 'indonesian' => 'id', 'italian' => 'it', 'japanese' => 'ja',
        'korean' => 'ko', 'macedonian' => 'mk', 'norwegian' => 'nb', 'polish' => 'pl', 'portuguese' => 'pt', 'portuguese-br' => 'pt-BR',
        'portuguese-pt' => 'pt-PT', 'romanian' => 'ro', 'russian' => 'ru', 'serbian' => 'sr', 'slovak' => 'sk', 'slovenian' => 'sl',
        'spanish' => 'es', 'swedish' => 'sv', 'thai' => 'th', 'turkish' => 'tr', 'ukranian' => 'uk', 'ukrainian' => 'uk', 'vietnamese' => 'vi',
    ];

    public static function main(): string
    {
        return self::normalize((string) config('help.main_language')) ?? 'en';
    }

    /** @return list<string> the languages content can be translated into */
    public static function others(): array
    {
        return array_values(array_diff(self::list((array) config('help.languages')), [self::main()]));
    }

    /** @return list<string> the main language first, then the others */
    public static function all(): array
    {
        return [self::main(), ...self::others()];
    }

    public static function name(string $code): string
    {
        return self::NAMES[$code] ?? $code;
    }

    /** A language's name in that language ("Deutsch", "Français"), for language pickers. */
    public static function nativeName(string $code): string
    {
        $name = class_exists(\Locale::class) ? \Locale::getDisplayName(str_replace('-', '_', $code), str_replace('-', '_', $code)) : '';

        return $name !== '' && $name !== str_replace('-', '_', $code) ? mb_convert_case(mb_substr($name, 0, 1), MB_CASE_UPPER).mb_substr($name, 1) : self::name($code);
    }

    /**
     * The language a visitor sees: the one they picked, else (signed in) the
     * one on their account, else the best match for their browser, else the
     * main one.
     */
    public static function current(): string
    {
        $all = self::all();

        if (count($all) === 1) {
            return $all[0];
        }

        $choices = [
            fn () => request()->hasSession() ? request()->session()->get(self::SESSION_KEY) : null,
            fn () => request()->hasSession() ? auth('client')->user()?->language : null,
        ];
        foreach ($choices as $choice) {
            $code = $choice();
            if (is_string($code) && in_array($code, $all, true)) {
                return $code;
            }
        }

        // Symfony writes locales with underscores and returns the first one when nothing matches.
        $preferred = request()->getPreferredLanguage(array_map(fn (string $code) => str_replace('-', '_', $code), $all));

        return self::normalize((string) $preferred) ?? $all[0];
    }

    /** The language a client's emails are written in. */
    public static function forClient(?Client $client): string
    {
        $language = $client?->language;

        return $language !== null && in_array($language, self::all(), true) ? $language : self::main();
    }

    /**
     * Languages CMS ships portal text for, from lang/*.json (English is the source, so always there).
     *
     * @return list<string>
     */
    public static function shipped(): array
    {
        $codes = array_map(fn (string $file) => basename($file, '.json'), glob(lang_path('*.json')) ?: []);

        return self::list(['en', ...$codes]);
    }

    /** Whether visitors see a translation rather than the main language. */
    public static function translating(): bool
    {
        return self::current() !== self::main();
    }

    /** "pt_br" or "PT-BR" -> "pt-BR"; null when it isn't a language code. */
    public static function normalize(string $code): ?string
    {
        if (! preg_match('/^([a-z]{2,3})(?:[-_]([a-z]{2}|[a-z]{4}))?$/i', trim($code), $m)) {
            return null;
        }

        $region = $m[2] ?? '';

        return strtolower($m[1]).match (strlen($region)) {
            0 => '',
            2 => '-'.strtoupper($region),
            default => '-'.ucfirst(strtolower($region)),
        };
    }

    /**
     * Language codes, cleaned up, without repeats or anything that isn't a code.
     *
     * @param  iterable<mixed>  $codes
     * @return list<string>
     */
    public static function list(iterable $codes): array
    {
        $list = [];

        foreach ($codes as $code) {
            $code = self::normalize((string) $code);

            if ($code !== null && ! in_array($code, $list, true)) {
                $list[] = $code;
            }
        }

        return $list;
    }

    /** The code of a WHMCS language ("french", "portuguese-br"), or null when it isn't known. */
    public static function fromWhmcs(string $name): ?string
    {
        $name = strtolower(trim($name));

        return self::WHMCS[$name] ?? self::normalize($name);
    }
}
