<?php

namespace App\Http\Controllers\Help;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\Response;
use Illuminate\View\View;

class AnnouncementController extends Controller
{
    public function index(): View
    {
        return view('help.announcements.index', ['announcements' => Announcement::visible()->paginate(10)]);
    }

    public function show(Announcement $announcement): View
    {
        abort_unless($announcement->isVisible(), 404);

        return view('help.announcements.show', compact('announcement'));
    }

    /** The latest announcements as an RSS 2.0 feed. */
    public function rss(): Response
    {
        return response()
            ->view('help.announcements.rss', ['announcements' => Announcement::visible()->limit(20)->get()])
            ->header('Content-Type', 'application/rss+xml; charset=UTF-8');
    }
}
