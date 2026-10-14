{!! '<'.'?xml version="1.0" encoding="UTF-8"?>' !!}
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
    <channel>
        <title>{{ config('app.name') }} announcements</title>
        <link>{{ route('announcements.index') }}</link>
        <atom:link href="{{ route('announcements.rss') }}" rel="self" type="application/rss+xml" />
        <description>News from {{ config('app.name') }}</description>
        @foreach ($announcements as $announcement)
            <item>
                <title>{{ $announcement->title }}</title>
                <link>{{ route('announcements.show', $announcement) }}</link>
                <guid>{{ route('announcements.show', $announcement) }}</guid>
                <pubDate>{{ $announcement->published_at->toRssString() }}</pubDate>
                <description>{{ App\Support\Markdown::html($announcement->body) }}</description>
            </item>
        @endforeach
    </channel>
</rss>
