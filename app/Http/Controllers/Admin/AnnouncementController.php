<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Announcement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AnnouncementController extends Controller
{
    public function index(): View
    {
        return view('admin.announcements.index', ['announcements' => Announcement::latest('published_at')->latest('id')->paginate(30)]);
    }

    public function create(): View
    {
        return view('admin.announcements.form', ['announcement' => new Announcement(['published' => true, 'published_at' => now()])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $announcement = Announcement::create($this->validated($request));
        Activity::record("Added announcement {$announcement->title}", $announcement);

        return redirect()->route('admin.announcements.index')->with('status', 'Announcement added.');
    }

    public function edit(Announcement $announcement): View
    {
        return view('admin.announcements.form', compact('announcement'));
    }

    public function update(Request $request, Announcement $announcement): RedirectResponse
    {
        $announcement->update($this->validated($request));
        Activity::record("Updated announcement {$announcement->title}", $announcement);

        return redirect()->route('admin.announcements.index')->with('status', 'Announcement saved.');
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        $announcement->delete();
        Activity::record("Deleted announcement {$announcement->title}");

        return redirect()->route('admin.announcements.index')->with('status', 'Announcement deleted.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:100000'],
            'published_at' => ['required', 'date'],
        ]);

        return $data + ['published' => $request->boolean('published')];
    }
}
