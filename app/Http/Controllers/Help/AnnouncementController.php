<?php

namespace App\Http\Controllers\Help;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Support\Markdown;
use Illuminate\Http\Response;
use Illuminate\View\View;
use SimpleXMLElement;

class AnnouncementController extends Controller
{
    public function index(): View
    {
        return view('help.announcements.index', ['announcements' => Announcement::visible()->translated()->paginate(10)]);
    }

    public function show(Announcement $announcement): View
    {
        abort_unless($announcement->isVisible(), 404);

        return view('help.announcements.show', ['announcement' => $announcement->loadTranslation()]);
    }

    /** The latest announcements as an RSS 2.0 feed. */
    public function rss(): Response
    {
        $xml = new SimpleXMLElement('<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"/>');
        $channel = $xml->addChild('channel');
        $channel->addChild('title', e(config('app.name').' announcements'));
        $channel->addChild('link', e(route('announcements.index')));
        $channel->addChild('description', e('News from '.config('app.name')));
        $self = $channel->addChild('link', null, 'http://www.w3.org/2005/Atom');
        $self->addAttribute('href', route('announcements.rss'));
        $self->addAttribute('rel', 'self');
        $self->addAttribute('type', 'application/rss+xml');

        foreach (Announcement::visible()->translated()->limit(20)->get() as $announcement) {
            $item = $channel->addChild('item');
            $item->addChild('title', e($announcement->title));
            $item->addChild('link', e(route('announcements.show', $announcement)));
            $item->addChild('guid', e(route('announcements.show', $announcement)));
            $item->addChild('pubDate', $announcement->published_at->toRssString());
            $item->addChild('description', e(Markdown::html($announcement->body)));
        }

        return response($xml->asXML(), 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
    }
}
