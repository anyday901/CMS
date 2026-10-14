<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\DownloadCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DownloadCategoryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $category = DownloadCategory::create($this->validated($request));
        Activity::record("Added download category {$category->name}", $category);

        return redirect()->route('admin.downloads.index')->with('status', 'Category added.');
    }

    public function edit(DownloadCategory $downloadCategory): View
    {
        return view('admin.downloads.category', ['category' => $downloadCategory]);
    }

    public function update(Request $request, DownloadCategory $downloadCategory): RedirectResponse
    {
        $downloadCategory->update($this->validated($request));
        Activity::record("Updated download category {$downloadCategory->name}", $downloadCategory);

        return redirect()->route('admin.downloads.index')->with('status', 'Category updated.');
    }

    public function destroy(DownloadCategory $downloadCategory): RedirectResponse
    {
        if ($downloadCategory->downloads()->exists()) {
            return back()->withErrors(['category' => 'Move or delete this category\'s downloads first.']);
        }

        $downloadCategory->delete();
        Activity::record("Deleted download category {$downloadCategory->name}");

        return redirect()->route('admin.downloads.index')->with('status', 'Category deleted.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        return [...$data, 'sort_order' => (int) ($data['sort_order'] ?? 0)];
    }
}
