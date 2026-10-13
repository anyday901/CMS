{{-- Moving the account to another server, or to another module after an upgrade. --}}
@include('admin.services._move_status')
@error('move_server')<p class="mb-3 rounded-md bg-red-50 px-3 py-2 text-red-800">{{ $message }}</p>@enderror
@can('manage-clients')
    @if ($moveTo->isNotEmpty())
        <details class="mb-3" @if ($errors->has('move_server')) open @endif>
            <summary class="cursor-pointer text-brand-600">Move to another server</summary>
            <form method="POST" action="{{ route('admin.account-moves.store', $service) }}" class="mt-3 max-w-3xl space-y-3" onsubmit="return confirm('Create the account on the new server?')">
                @csrf
                <p class="text-ink-600">Creates the account on the new server with the same domain, username and password. The old account stays until you have copied the data over and finish the move.</p>
                <label class="block">New server
                    <select name="to_server_id" class="mt-1 block w-full max-w-md rounded-md px-3 py-2 ring-1 ring-ink-300">
                        @foreach ($moveTo as $server)
                            <option value="{{ $server->id }}">{{ $server->name }} ({{ $server->hostname }}, {{ $server->account_count }} of {{ $server->max_accounts }} accounts){{ in_array($server->status, [App\Models\Server::DOWN, App\Models\Server::WARNING], true) ? ', '.strtolower($server->statusLabel()) : '' }}</option>
                        @endforeach
                    </select>
                </label>
                @if ($copyProblems !== null)
                    <label class="flex items-start gap-2"><input type="checkbox" name="copy_data" value="1" class="mt-1" @checked(old('copy_data', $copyProblems->filter()->count() < $copyProblems->count()))>
                        <span>Copy the account with its data automatically ({{ $module->key() === 'proxmox' ? 'Proxmox migration' : 'cPanel transfer' }}) instead of making an empty one.</span></label>
                    @foreach ($copyProblems->filter() as $serverId => $copyProblem)
                        <p class="ml-6 text-ink-500">Not to {{ $moveTo->firstWhere('id', $serverId)->name }}: {{ $copyProblem }}</p>
                    @endforeach
                @endif
                <label class="flex items-start gap-2"><input type="checkbox" name="remove_at_once" value="1" class="mt-1">
                    <span>There's nothing to copy, or the copy needs no checking: remove the old account as soon as the new one is ready.</span></label>
                <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Move</button>
            </form>
        </details>
    @endif
@endcan
