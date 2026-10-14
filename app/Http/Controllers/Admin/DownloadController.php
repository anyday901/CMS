<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Download;
use App\Models\DownloadCategory;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Files in the downloads area, kept on the private disk. */
class DownloadController extends Controller
{
    /** Largest upload, in KB, from config/support.php. */
    public static function maxKb(): int
    {
        return max(1, config('support.download_max_mb')) * 1024;
    }

    public function index(): View
    {
        return view('admin.downloads.index', [
            'categories' => DownloadCategory::ordered()->with('downloads.products')->get(),
            'category' => new DownloadCategory,
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.downloads.form', [
            'download' => new Download(['download_category_id' => $request->integer('category') ?: null]),
            'categories' => DownloadCategory::ordered()->get(),
            'products' => Product::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, true);
        $download = Download::create($data + $this->stored($request));
        $download->products()->sync($data['product_ids'] ?? []);
        Activity::record("Added download {$download->title}", $download);

        return redirect()->route('admin.downloads.index')->with('status', 'Download added.');
    }

    public function edit(Download $download): View
    {
        return view('admin.downloads.form', [
            'download' => $download->load('products'),
            'categories' => DownloadCategory::ordered()->get(),
            'products' => Product::orderBy('name')->get(),
        ]);
    }

    /** A new file replaces the old one. */
    public function update(Request $request, Download $download): RedirectResponse
    {
        $data = $this->validated($request, false);
        $old = $request->hasFile('file') ? $download->path : null;
        $download->update($data + ($request->hasFile('file') ? $this->stored($request) : []));
        $download->products()->sync($data['product_ids'] ?? []);

        if ($old) {
            Storage::disk('local')->delete($old);
        }
        Activity::record("Updated download {$download->title}", $download);

        return redirect()->route('admin.downloads.index')->with('status', 'Download saved.');
    }

    public function destroy(Download $download): RedirectResponse
    {
        $download->delete();
        Storage::disk('local')->delete($download->path);
        Activity::record("Deleted download {$download->title}");

        return redirect()->route('admin.downloads.index')->with('status', 'Download deleted.');
    }

    private function validated(Request $request, bool $new): array
    {
        $data = $request->validate([
            'download_category_id' => ['required', 'exists:download_categories,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'file' => [$new ? 'required' : 'nullable', 'file', 'max:'.self::maxKb()],
            'product_ids' => ['array'],
            'product_ids.*' => ['exists:products,id'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
        unset($data['file']);

        return [...$data, 'sort_order' => (int) ($data['sort_order'] ?? 0), 'clients_only' => $request->boolean('clients_only')];
    }

    /** @return array{path: string, file_name: string, mime: ?string, size: int} */
    private function stored(Request $request): array
    {
        $file = $request->file('file');

        return [
            'path' => $file->storeAs('downloads', Str::random(40), 'local'),
            'file_name' => Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 200, ''),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
        ];
    }
}
