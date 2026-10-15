<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Announcement;
use App\Models\KbArticle;
use App\Support\Language;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Adds, changes and removes the translations of knowledge base articles and announcements. */
class TranslationController extends Controller
{
    /** Route segment => what can be translated, and where to go back to. */
    private const TYPES = [
        'kb-articles' => [KbArticle::class, 'admin.kb-articles.edit', 'knowledge base article'],
        'announcements' => [Announcement::class, 'admin.announcements.edit', 'announcement'],
    ];

    public function update(Request $request, string $type, int $id, string $locale): RedirectResponse
    {
        [$model, $back, $noun] = $this->find($type, $id, $locale);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:200000'],
            'tags' => [Rule::prohibitedIf($type !== 'kb-articles'), 'nullable', 'string', 'max:255'],
        ]);

        $model->translations()->updateOrCreate(['locale' => $locale], [
            'title' => $data['title'],
            'body' => $data['body'],
            'tags' => filled($data['tags'] ?? null) ? $data['tags'] : null,
        ]);
        Activity::record('Saved the '.Language::name($locale)." translation of {$noun} {$model->getRawOriginal('title')}", $model);

        return redirect()->to(route($back, $model).'#translation-'.$locale)->with('status', Language::name($locale).' translation saved.');
    }

    public function destroy(string $type, int $id, string $locale): RedirectResponse
    {
        [$model, $back, $noun] = $this->find($type, $id, $locale);
        $model->translations()->where('locale', $locale)->delete();
        Activity::record('Removed the '.Language::name($locale)." translation of {$noun} {$model->getRawOriginal('title')}", $model);

        return redirect()->to(route($back, $model).'#translations')->with('status', Language::name($locale).' translation removed.');
    }

    /** @return array{0: KbArticle|Announcement, 1: string, 2: string} */
    private function find(string $type, int $id, string $locale): array
    {
        [$class, $back, $noun] = self::TYPES[$type] ?? abort(404);
        abort_unless(in_array($locale, Language::others(), true), 404);

        /** @var Model $model */
        $model = $class::findOrFail($id);

        return [$model, $back, $noun];
    }
}
