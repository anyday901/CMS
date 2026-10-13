<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountMove;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\AccountMover;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Staff move a service's account to another server, then finish or cancel the move. */
class AccountMoveController extends Controller
{
    private const MOVE = 'move_server';

    public function __construct(private AccountMover $mover) {}

    public function store(Request $request, Service $service): RedirectResponse
    {
        $data = $request->validate(['to_server_id' => ['required', Rule::exists('servers', 'id')]]);

        try {
            $move = $this->mover->toServer($service, Server::findOrFail($data['to_server_id']), $request->boolean('remove_at_once'), $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([self::MOVE => $e->getMessage()]);
        }

        return back()->with('status', "Creating the account on {$move->toServer->name}. The old one stays until you finish the move.");
    }

    /** Removes the old account, once the data has been copied. */
    public function finish(AccountMove $move): RedirectResponse
    {
        abort_unless(in_array($move->status, [AccountMove::COPYING, AccountMove::FAILED], true), 422, 'This move isn\'t waiting to be finished.');
        $this->mover->finish($move);

        return back()->with('status', 'Removing the old account.');
    }

    public function destroy(AccountMove $move): RedirectResponse
    {
        try {
            $this->mover->cancel($move);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([self::MOVE => $e->getMessage()]);
        }

        return back()->with('status', 'Move cancelled. The service is back on its old account.');
    }
}
