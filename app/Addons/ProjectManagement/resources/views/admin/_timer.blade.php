{{-- $timer: the signed-in staff member's running timer, or null --}}
@if ($timer)
    <div class="mb-6 flex flex-wrap items-center gap-3 rounded-md bg-brand-50 px-4 py-3 text-sm text-brand-800">
        <span>Your timer is running on <a href="{{ route('admin.projects.show', $timer->project) }}" class="font-medium underline">{{ $timer->project->title }}</a>@if ($timer->task) ({{ $timer->task->title }})@endif, started {{ $timer->started_at->diffForHumans() }}: {{ App\Addons\ProjectManagement\Duration::format($timer->minutes()) }} so far.</span>
        <div class="ml-auto flex items-center gap-2">
            <form method="POST" action="{{ route('admin.projects.time.timer.discard') }}" onsubmit="return confirm('Throw this timer away without logging the time?')">
                @csrf @method('DELETE')
                <button class="text-sm underline">Discard</button>
            </form>
            <form method="POST" action="{{ route('admin.projects.time.timer.stop') }}">
                @csrf
                <button class="rounded-full bg-brand-600 px-3 py-1.5 font-medium text-white hover:bg-brand-500">Stop and log time</button>
            </form>
        </div>
    </div>
@endif
