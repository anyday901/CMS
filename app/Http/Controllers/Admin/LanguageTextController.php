<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\TranslationOverride;
use App\Support\Language;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * Settings > Portal text: change any portal wording, in any language,
 * including languages CMS doesn't ship a translation for.
 */
class LanguageTextController extends Controller
{
    private const PER_PAGE = 50;

    public function index(): View
    {
        $loader = app('translation.loader');
        $catalogue = $loader->catalogue();
        $overrides = TranslationOverride::selectRaw('locale, count(*) as total')->groupBy('locale')->pluck('total', 'locale');

        $languages = collect(Language::list([...Language::all(), ...Language::shipped()]))->map(fn (string $code) => [
            'code' => $code,
            'name' => Language::name($code),
            'offered' => in_array($code, Language::all(), true),
            'translated' => $code === 'en' ? count($catalogue) : count(array_intersect_key($loader->shipped($code), array_flip($catalogue))),
            'overrides' => (int) ($overrides[$code] ?? 0),
        ]);

        return view('admin.languages.index', ['languages' => $languages, 'total' => count($catalogue)]);
    }

    public function edit(Request $request, string $locale): View
    {
        $locale = $this->locale($locale);
        $loader = app('translation.loader');
        $shipped = $loader->shipped($locale);
        $overrides = TranslationOverride::where('locale', $locale)->pluck('value', 'key');
        $search = trim((string) $request->query('q'));
        $filter = (string) $request->query('show');

        $rows = collect($loader->catalogue())
            ->map(fn (string $key) => ['key' => $key, 'shipped' => $shipped[$key] ?? null, 'override' => $overrides[$key] ?? null])
            ->filter(fn (array $row) => $search === '' || str_contains(mb_strtolower($row['key'].' '.$row['shipped'].' '.$row['override']), mb_strtolower($search)))
            ->filter(fn (array $row) => match ($filter) {
                'missing' => $locale !== 'en' && $row['shipped'] === null && $row['override'] === null,
                'changed' => $row['override'] !== null,
                default => true,
            })
            ->values();

        $page = LengthAwarePaginator::resolveCurrentPage();

        return view('admin.languages.edit', [
            'locale' => $locale,
            'name' => Language::name($locale),
            'offered' => in_array($locale, Language::all(), true),
            'rows' => new LengthAwarePaginator($rows->forPage($page, self::PER_PAGE)->values(), $rows->count(), self::PER_PAGE, $page, ['path' => $request->url(), 'query' => $request->query()]),
            'search' => $search,
            'filter' => $filter,
        ]);
    }

    public function update(Request $request, string $locale): RedirectResponse
    {
        $locale = $this->locale($locale);
        $data = $request->validate([
            'rows' => ['array', 'max:200'],
            'rows.*.key' => ['required', 'string', 'max:5000'],
            'rows.*.value' => ['nullable', 'string', 'max:5000'],
        ]);
        $catalogue = array_flip(app('translation.loader')->catalogue());
        $changed = 0;

        foreach ($data['rows'] ?? [] as $row) {
            if (! isset($catalogue[$row['key']])) {
                continue;
            }

            $existing = TranslationOverride::where('locale', $locale)->where('key_hash', TranslationOverride::hash($row['key']))->first();
            $value = $row['value'] ?? null;

            if (blank($value)) {
                $changed += $existing ? (int) $existing->delete() : 0;
            } elseif ($existing?->value !== $value) {
                TranslationOverride::updateOrCreate(['locale' => $locale, 'key_hash' => TranslationOverride::hash($row['key'])], ['key' => $row['key'], 'value' => $value]);
                $changed++;
            }
        }

        if ($changed > 0) {
            app('translator')->setLoaded([]);
            Activity::record("Changed {$changed} portal text(s) in ".Language::name($locale));
        }

        return back()->with('status', $changed === 0 ? 'Nothing changed.' : "Saved {$changed} change(s).");
    }

    private function locale(string $locale): string
    {
        $code = Language::normalize($locale);
        abort_if($code === null, 404);

        return $code;
    }
}
