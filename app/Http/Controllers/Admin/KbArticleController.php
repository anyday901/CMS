<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\KbArticle;
use App\Models\KbCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class KbArticleController extends Controller
{
    public function create(Request $request): View
    {
        return view('admin.kb.article', [
            'article' => new KbArticle(['published' => true, 'kb_category_id' => $request->integer('category') ?: null]),
            'categories' => $this->categories(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $article = KbArticle::create($this->validated($request));
        Activity::record("Added knowledge base article {$article->title}", $article);

        return redirect()->route('admin.kb-articles.edit', $article)->with('status', 'Article added.');
    }

    public function edit(KbArticle $kbArticle): View
    {
        return view('admin.kb.article', ['article' => $kbArticle, 'categories' => $this->categories()]);
    }

    public function update(Request $request, KbArticle $kbArticle): RedirectResponse
    {
        $kbArticle->update($this->validated($request));
        Activity::record("Updated knowledge base article {$kbArticle->title}", $kbArticle);

        return redirect()->route('admin.kb-articles.edit', $kbArticle)->with('status', 'Article saved.');
    }

    public function destroy(KbArticle $kbArticle): RedirectResponse
    {
        $kbArticle->delete();
        Activity::record("Deleted knowledge base article {$kbArticle->title}");

        return redirect()->route('admin.kb.index')->with('status', 'Article deleted.');
    }

    private function categories()
    {
        return KbCategory::whereNull('parent_id')->ordered()->with('children')->get();
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'kb_category_id' => ['required', 'exists:kb_categories,id'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:200000'],
            'tags' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        return [...$data, 'sort_order' => (int) ($data['sort_order'] ?? 0), 'published' => $request->boolean('published')];
    }
}
