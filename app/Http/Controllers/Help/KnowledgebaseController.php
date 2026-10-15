<?php

namespace App\Http\Controllers\Help;

use App\Http\Controllers\Controller;
use App\Models\KbArticle;
use App\Models\KbCategory;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The public knowledge base: categories, articles and search. */
class KnowledgebaseController extends Controller
{
    /** The public help pages, which the portal's Support menu item covers. */
    public const HELP_ROUTES = ['kb.*', 'announcements.*', 'network.*', 'downloads.*', 'tickets.guest.*'];

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));

        return view('help.kb.index', [
            'search' => $search,
            'results' => $search === '' ? null : KbArticle::visible()->search($search)->with(self::withCategory())->translated()->ordered()->paginate(20)->withQueryString(),
            'categories' => KbCategory::visible()->whereNull('parent_id')->ordered()->translated()
                ->withCount(['articles' => fn ($query) => $query->where('published', true)])
                ->with(['children' => fn ($query) => $query->where('visible', true)->translated()])
                ->get(),
            'popular' => KbArticle::visible()->translated()->orderByDesc('views')->orderBy('title')->limit(5)->get(),
        ]);
    }

    public function category(KbCategory $category): View
    {
        abort_unless($category->isVisible(), 404);

        $category->loadTranslation()->parent?->loadTranslation();

        return view('help.kb.category', [
            'category' => $category,
            'children' => $category->children()->where('visible', true)->translated()->withCount(['articles' => fn ($query) => $query->where('published', true)])->get(),
            'articles' => $category->articles()->where('published', true)->translated()->get(),
        ]);
    }

    public function article(Request $request, KbArticle $article): View
    {
        abort_unless($article->isVisible(), 404);

        // Count a view once per visitor session.
        if (! $request->session()->has("kb_viewed.{$article->id}")) {
            $request->session()->put("kb_viewed.{$article->id}", true);
            $article->increment('views');
        }

        return view('help.kb.article', [
            'article' => $article->loadTranslation()->load(self::withCategory()),
            'voted' => $request->session()->has("kb_voted.{$article->id}"),
            'related' => KbArticle::visible()->where('kb_category_id', $article->kb_category_id)->whereKeyNot($article->id)->translated()->ordered()->limit(5)->get(),
        ]);
    }

    /** @return array<string, Closure> an article's category and the one above it, in the visitor's language */
    private static function withCategory(): array
    {
        $translated = fn ($query) => $query->translated();

        return ['category' => $translated, 'category.parent' => $translated];
    }

    /** "Was this article helpful?" Once per visitor session. */
    public function vote(Request $request, KbArticle $article): RedirectResponse
    {
        abort_unless($article->isVisible(), 404);
        $request->validate(['helpful' => ['required', 'boolean']]);

        if (! $request->session()->has("kb_voted.{$article->id}")) {
            $request->session()->put("kb_voted.{$article->id}", true);
            $article->increment($request->boolean('helpful') ? 'helpful' : 'not_helpful');
        }

        return redirect()->route('kb.article', $article)->with('status', 'Thanks for your feedback.');
    }
}
