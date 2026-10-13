{{-- Where an open account move stands, with its controls. --}}
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
