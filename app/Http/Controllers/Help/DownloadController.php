<?php

namespace App\Http\Controllers\Help;

use App\Http\Controllers\Controller;
use App\Models\Download;
use App\Models\DownloadCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The downloads area. Every file is listed; who can download it is checked when they do. */
class DownloadController extends Controller
{
    public function index(Request $request): View
    {
        $categories = DownloadCategory::ordered()->with('downloads.products')->get()
            ->filter(fn (DownloadCategory $category) => $category->downloads->isNotEmpty());

        return view('help.downloads.index', ['categories' => $categories, 'client' => $request->user('client')]);
    }

    public function file(Request $request, Download $download): StreamedResponse|RedirectResponse
    {
        $client = $request->user('client');

        if ($client === null && $download->needsLogin()) {
            return redirect()->guest(route('portal.login'));
        }

        abort_unless($download->allows($client), 403, 'This download is for clients with '.$download->products->pluck('name')->join(', ', ' or ').'.');
        $download->increment('downloads');

        return Storage::disk('local')->download($download->path, $download->file_name, ['X-Content-Type-Options' => 'nosniff']);
    }
}
