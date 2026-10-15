@extends('install._layout')
@section('content')
    <section class="space-y-4 rounded-lg border border-ink-200 bg-white p-6 text-sm">
        <h1 class="text-2xl">CMS is set up</h1>
        <p>Your staff account is ready. This installer is now closed.</p>
        <p class="text-ink-600">Three things to do on the server, if you haven't yet:</p>
        <ul class="list-inside list-disc space-y-1 text-ink-600">
            <li>Add the cron entry: <code class="rounded bg-ink-100 px-1">* * * * * cd {{ base_path() }} &amp;&amp; php artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</code></li>
            <li>Run a queue worker (<code class="rounded bg-ink-100 px-1">php artisan queue:work</code> under systemd or Supervisor) so emails go out.</li>
            <li>Make .env read-only for the web server again.</li>
        </ul>
        <a href="{{ $login }}" class="inline-block rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Log in to the admin area</a>
    </section>
@endsection
