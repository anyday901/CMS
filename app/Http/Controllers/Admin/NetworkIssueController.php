<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\NetworkIssue;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Planned work and current problems for the network status page. */
class NetworkIssueController extends Controller
{
    public function index(): View
    {
        return view('admin.network-issues.index', [
            'open' => NetworkIssue::where('status', '!=', NetworkIssue::RESOLVED)->with('server')->orderBy('starts_at')->get(),
            'resolved' => NetworkIssue::resolved()->with('server')->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('admin.network-issues.form', [
            'issue' => new NetworkIssue(['status' => 'investigating', 'priority' => 'medium', 'starts_at' => now()]),
            'servers' => Server::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $issue = NetworkIssue::create($this->validated($request));
        Activity::record("Added network issue {$issue->title}", $issue);

        return redirect()->route('admin.network-issues.index')->with('status', 'Network issue added.');
    }

    public function edit(NetworkIssue $networkIssue): View
    {
        return view('admin.network-issues.form', ['issue' => $networkIssue, 'servers' => Server::orderBy('name')->get()]);
    }

    public function update(Request $request, NetworkIssue $networkIssue): RedirectResponse
    {
        $networkIssue->update($this->validated($request));
        Activity::record("Updated network issue {$networkIssue->title}", $networkIssue, ['status' => $networkIssue->status]);

        return redirect()->route('admin.network-issues.index')->with('status', 'Network issue saved.');
    }

    /** Marks it resolved now, keeping an end time staff already set. */
    public function resolve(NetworkIssue $networkIssue): RedirectResponse
    {
        $networkIssue->update(['status' => NetworkIssue::RESOLVED, 'ends_at' => $networkIssue->ends_at ?? now()]);
        Activity::record("Resolved network issue {$networkIssue->title}", $networkIssue);

        return back()->with('status', 'Network issue resolved.');
    }

    public function destroy(NetworkIssue $networkIssue): RedirectResponse
    {
        $networkIssue->delete();
        Activity::record("Deleted network issue {$networkIssue->title}");

        return redirect()->route('admin.network-issues.index')->with('status', 'Network issue deleted.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:20000'],
            'server_id' => ['nullable', 'exists:servers,id'],
            'affecting' => ['nullable', 'string', 'max:255'],
            'priority' => ['required', Rule::in(array_keys(NetworkIssue::PRIORITIES))],
            'status' => ['required', Rule::in(array_keys(NetworkIssue::STATUSES))],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        if ($data['status'] === NetworkIssue::RESOLVED && empty($data['ends_at'])) {
            $data['ends_at'] = now();
        }

        return $data;
    }
}
