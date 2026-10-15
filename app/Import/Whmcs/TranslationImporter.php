<?php

namespace App\Import\Whmcs;

use App\Models\Announcement;
use App\Models\KbArticle;
use App\Support\Language;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Translations of knowledge base articles and announcements, and the
 * languages they bring: WHMCS's default language becomes the main one, and
 * the languages translations came in are offered to visitors.
 */
class TranslationImporter
{
    private const TRANSLATIONS = 'Translations';

    /** The language articles are written in: WHMCS's default, unless one was already picked here. */
    private string $main;

    /** @var array<string, true> languages translations were imported in */
    private array $languages = [];

    /** @var array<string, true> "type|id|locale" of the translations imported */
    private array $translated = [];

    public function __construct(private ImportRun $run)
    {
        $this->main = $this->mainLanguage();
    }

    /** Whether a row is the original rather than a translation of another one. */
    public static function original(object $row, string $parentColumn): bool
    {
        return (int) ($row->{$parentColumn} ?? 0) === 0 && trim((string) ($row->language ?? '')) === '';
    }

    /**
     * A WHMCS translation of an article or announcement: a row of its own
     * that names the original as its parent, in a language WHMCS names after
     * its language files ("french").
     *
     * @param  class-string<KbArticle|Announcement>  $class
     * @param  array<int, int>  $originals  WHMCS id => imported id
     * @param  Collection<int, object>|null  $tags
     */
    public function import(string $class, array $originals, object $row, string $titleColumn, string $bodyColumn, ?Collection $tags = null): void
    {
        $this->run->report->found(self::TRANSLATIONS);
        $title = Whmcs::text($row->{$titleColumn});
        $language = trim((string) ($row->language ?? ''));
        $locale = Language::fromWhmcs($language);
        $noun = $class === KbArticle::class ? 'article' : 'announcement';

        $problem = match (true) {
            ! isset($originals[(int) $row->parentid]) => "the {$noun} it translates wasn't imported",
            $locale === null => "WHMCS's language \"{$language}\" has no language code here",
            $locale === $this->main => "it's in the main language, ".Language::name($locale),
            default => null,
        };

        if ($problem !== null) {
            $this->run->report->skipped(self::TRANSLATIONS, "\"{$title}\" ({$language}) was left out: {$problem}.");

            return;
        }

        $model = new $class;
        $key = [$model->getMorphClass(), $originals[(int) $row->parentid], $locale];

        // WHMCS can hold two translations into one language; the first wins.
        if (isset($this->translated[implode('|', $key)])) {
            $this->run->report->skipped(self::TRANSLATIONS, "\"{$title}\" is a second ".Language::name($locale)." translation of one {$noun}, so it was left out.");

            return;
        }

        DB::table('content_translations')->insert([
            'translatable_type' => $key[0],
            'translatable_id' => $key[1],
            'locale' => $locale,
            'title' => Str::limit($title, 250, '') ?: 'Untitled',
            'body' => HtmlToMarkdown::convert($row->{$bodyColumn} ?? '') ?: SupportImporter::NO_TEXT,
            'tags' => $tags === null ? null : self::tags($tags),
            'created_at' => $this->run->now,
            'updated_at' => $this->run->now,
        ]);
        $this->translated[implode('|', $key)] = true;
        $this->languages[$locale] = true;
        $this->run->report->imported(self::TRANSLATIONS);
    }

    /** WHMCS's default language, unless this install already has a main language under Settings. */
    private function mainLanguage(): string
    {
        $whmcs = Language::fromWhmcs((string) $this->run->setting('Language'));
        $picked = DB::table('settings')->where('key', 'help.main_language')->exists();

        return $whmcs !== null && ! $picked ? $whmcs : Language::main();
    }

    /** Saves the main language and the languages translations came in under Settings, so visitors see them. */
    public function offerLanguages(): void
    {
        $changed = $this->main !== Language::main();

        if ($changed) {
            $this->run->report->note(self::TRANSLATIONS, 'The main language is now '.Language::name($this->main).', WHMCS\'s default language. Change it under Settings if articles are written in another one.');
        }

        if ($this->languages !== []) {
            $this->run->report->note(self::TRANSLATIONS, 'Translations came over in '.collect(array_keys($this->languages))->map(fn (string $code) => Language::name($code))->join(', ', ' and ').', and visitors can now pick those languages. Knowledge base categories keep their main language names.');
        }

        if (! $this->run->report->dryRun && ($changed || $this->languages !== [])) {
            Settings::save([
                'help.main_language' => $this->main,
                'help.languages' => array_values(array_diff(Language::list([...Language::others(), ...array_keys($this->languages)]), [$this->main])),
            ]);
        }
    }

    /** @param  Collection<int, object>  $tags */
    private static function tags(Collection $tags): ?string
    {
        return Str::limit($tags->map(fn ($tag) => Whmcs::text($tag->tag))->filter()->unique()->join(', '), 250, '') ?: null;
    }
}
