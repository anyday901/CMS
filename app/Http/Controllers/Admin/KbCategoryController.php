<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\KbArticle;
use App\Models\KbCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Knowledge base categories, and the list of every article. */
class KbCategoryController extends Controller
{
    public function index(Request $request): View
    {
        $category = $request->integer('category') ?: null;

        return view('admin.kb.index', [
            'categories' => KbCategory::whereNull('parent_id')->ordered()->with('children')->withCount('articles')->get(),
            'articles' => KbArticle::with('category.parent')
                ->when($category, fn ($query, $id) => $query->where('kb_category_id', $id))
                ->ordered()->paginate(50)->withQueryString(),
            'filter' => $category,
            'category' => new KbCategory,
            'parents' => $this->parents(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $category = KbCategory::create($this->validated($request));
        Activity::record("Added knowledge base category {$category->name}", $category);

        return redirect()->route('admin.kb.index')->with('status', 'Category added.');
    }

    public function edit(KbCategory $kbCategory): View
    {
        return view('admin.kb.category', ['category' => $kbCategory, 'parents' => $this->parents($kbCategory)]);
    }

    public function update(Request $request, KbCategory $kbCategory): RedirectResponse
    {
        $kbCategory->update($this->validated($request, $kbCategory));
        Activity::record("Updated knowledge base category {$kbCategory->name}", $kbCategory);

        return redirect()->route('admin.kb.index')->with('status', 'Category updated.');
    }

    /** Only an empty category can go. */
    public function destroy(KbCategory $kbCategory): RedirectResponse
    {
        if ($kbCategory->articles()->exists() || $kbCategory->children()->exists()) {
            return back()->withErrors(['category' => 'Move or delete this category\'s articles and subcategories first.']);
        }

        $kbCategory->delete();
        Activity::record("Deleted knowledge base category {$kbCategory->name}");

        return redirect()->route('admin.kb.index')->with('status', 'Category deleted.');
    }

    /** Top-level categories a category can go under. One with subcategories stays top-level. */
    private function parents(?KbCategory $category = null)
    {
        return KbCategory::whereNull('parent_id')->when($category, fn ($query) => $query->whereKeyNot($category->id))->ordered()->get();
    }

    private function validated(Request $request, ?KbCategory $category = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'parent_id' => ['nullable', Rule::exists('kb_categories', 'id')->whereNull('parent_id')],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        if ($category && $data['parent_id'] && ((int) $data['parent_id'] === $category->id || $category->children()->exists())) {
            $data['parent_id'] = null;
        }

        return [...$data, 'sort_order' => (int) ($data['sort_order'] ?? 0), 'visible' => ! $request->boolean('hidden')];
    }
}
