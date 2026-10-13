<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Server;
use App\Models\ServerGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ServerGroupController extends Controller
{
    private const INDEX = 'admin.servers.index';

    public function create(): View
    {
        return $this->form(new ServerGroup);
    }

    public function store(Request $request): RedirectResponse
    {
        $group = new ServerGroup;
        $this->save($request, $group);
        Activity::record("Added server group {$group->name}", $group);

        return redirect()->route(self::INDEX)->with('status', 'Server group added.');
    }

    public function edit(ServerGroup $serverGroup): View
    {
        return $this->form($serverGroup->load('servers'));
    }

    public function update(Request $request, ServerGroup $serverGroup): RedirectResponse
    {
        $this->save($request, $serverGroup);
        Activity::record("Updated server group {$serverGroup->name}", $serverGroup);

        return redirect()->route(self::INDEX)->with('status', 'Server group updated.');
    }

    /** Products using it stay, and put new services on any server of their module. */
    public function destroy(ServerGroup $serverGroup): RedirectResponse
    {
        $serverGroup->delete();
        Activity::record("Deleted server group {$serverGroup->name}");

        return redirect()->route(self::INDEX)->with('status', 'Server group deleted.');
    }

    private function form(ServerGroup $group): View
    {
        return view('admin.server-groups.form', ['group' => $group, 'servers' => Server::orderBy('name')->get()]);
    }

    private function save(Request $request, ServerGroup $group): void
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'fill_type' => ['required', Rule::in(array_keys(ServerGroup::FILL_TYPES))],
            'servers' => ['array'],
            'servers.*' => [Rule::exists('servers', 'id')],
        ]);

        DB::transaction(function () use ($group, $data) {
            $group->fill(['name' => $data['name'], 'fill_type' => $data['fill_type']])->save();
            $group->servers()->sync(array_map('intval', $data['servers'] ?? []));
        });
    }
}
