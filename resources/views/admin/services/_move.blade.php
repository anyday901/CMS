{{-- Moving the account to another server, or to another module after an upgrade. --}}
@if ($move = $service->openMove)
    <div class="mb-3 rounded-md bg-sun-300/40 px-3 py-2 text-amber-900">
        <div class="font-medium">Moving{{ $move->fromServer ? " from {$move->fromServer->name}" : '' }}{{ $service->server ? " to {$service->server->name}" : '' }}{{ $move->changesProduct() && $move->fromProduct ? " (was {$move->fromProduct->name})" : '' }}: {{ strtolower($move->statusLabel()) }}</div>
        @if ($move->status === App\Models\AccountMove::CREATING)
            <p>The new account is being created. The client keeps using the old one{{ $move->from_username ? " ({$move->from_username})" : '' }} until the move is finished.</p>
        @elseif ($move->status === App\Models\AccountMove::COPYING)
            <p>The new account is ready. Copy the client's data over from the old account{{ $move->from_username ? " ({$move->from_username})" : '' }}, then finish the move to remove the old one.</p>
        @elseif ($move->status === App\Models\AccountMove::REMOVING)
            <p>The old account is being removed.</p>
        @elseif ($move->status === App\Models\AccountMove::FAILED)
            <p class="text-red-800">{{ $move->error }}</p>
        @endif
        @can('manage-clients')
            <div class="mt-2 flex flex-wrap gap-2">
                @if (in_array($move->status, [App\Models\AccountMove::COPYING, App\Models\AccountMove::FAILED], true))
                    <form method="POST" action="{{ route('admin.account-moves.finish', $move) }}" onsubmit="return confirm('Remove the old account? Its data is deleted.')">@csrf
                        <button class="rounded-md bg-white px-3 py-1 ring-1 ring-amber-400 hover:bg-sun-300/40">{{ $move->status === App\Models\AccountMove::FAILED ? 'Try removing the old account again' : 'Finish the move: remove the old account' }}</button>
                    </form>
                @endif
                @if ($move->status === App\Models\AccountMove::CREATING && ! $move->changesProduct() && $service->provisioning_status !== 'pending')
                    <form method="POST" action="{{ route('admin.account-moves.destroy', $move) }}">@csrf @method('DELETE')
                        <button class="rounded-md bg-white px-3 py-1 ring-1 ring-amber-400 hover:bg-sun-300/40">Cancel the move</button>
                    </form>
                @endif
            </div>
        @endcan
    </div>
@endif
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
                            <option value="{{ $server->id }}">{{ $server->name }} ({{ $server->hostname }}, {{ $server->account_count }} of {{ $server->max_accounts }} accounts)</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex items-start gap-2"><input type="checkbox" name="remove_at_once" value="1" class="mt-1">
                    <span>There's nothing to copy: remove the old account as soon as the new one is ready.</span></label>
                <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Move</button>
            </form>
        </details>
    @endif
@endcan
