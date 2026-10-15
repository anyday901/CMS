<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Announcement;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Support\Language;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Adds, changes and removes the translations of knowledge base articles, categories and announcements. */
class TranslationController extends Controller
{
    /** Route segment => what can be translated, where to go back to, what it's called, and the column it's named by. */
    private const TYPES = [
        'kb-articles' => [KbArticle::class, 'admin.kb-articles.edit', 'knowledge base article', 'title'],
        'kb-categories' => [KbCategory::class, 'admin.kb-categories.edit', 'knowledge base category', 'name'],
        'announcements' => [Announcement::class, 'admin.announcements.edit', 'announcement', 'title'],
    ];

    /** Route segments whose translation is a name and an optional description rather than a title and text. */
    private const NAMED = ['kb-categories'];

    public function update(Request $request, string $type, int $id, string $locale): RedirectResponse
    {
        [$model, $back, $noun, $name] = $this->find($type, $id, $locale);
        $named = in_array($type, self::NAMED, true);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => $named ? ['nullable', 'string', 'max:2000'] : ['required', 'string', 'max:200000'],
            'tags' => [Rule::prohibitedIf($type !== 'kb-articles'), 'nullable', 'string', 'max:255'],
        ]);

        $model->translations()->updateOrCreate(['locale' => $locale], [
            'title' => $data['title'],
            'body' => filled($data['body'] ?? null) ? $data['body'] : null,
            'tags' => filled($data['tags'] ?? null) ? $data['tags'] : null,
        ]);
        Activity::record('Saved the '.Language::name($locale)." translation of {$noun} {$model->getRawOriginal($name)}", $model);

        return redirect()->to(route($back, $model).'#translation-'.$locale)->with('status', Language::name($locale).' translation saved.');
    }

    public function destroy(string $type, int $id, string $locale): RedirectResponse
    {
        [$model, $back, $noun, $name] = $this->find($type, $id, $locale);
        $model->translations()->where('locale', $locale)->delete();
        Activity::record('Removed the '.Language::name($locale)." translation of {$noun} {$model->getRawOriginal($name)}", $model);

        return redirect()->to(route($back, $model).'#translations')->with('status', Language::name($locale).' translation removed.');
    }

    /** @return array{0: KbArticle|KbCategory|Announcement, 1: string, 2: string, 3: string} */
    private function find(string $type, int $id, string $locale): array
    {
        [$class, $back, $noun, $name] = self::TYPES[$type] ?? abort(404);
        abort_unless(in_array($locale, Language::others(), true), 404);

        /** @var Model $model */
        $model = $class::findOrFail($id);

        return [$model, $back, $noun, $name];
    }
}
