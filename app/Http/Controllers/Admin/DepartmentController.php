<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TicketPriority;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Department;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Support departments and their response time targets. Turned off rather than deleted, so old tickets keep theirs. */
class DepartmentController extends Controller
{
    public function index(): View
    {
        $departments = Department::withCount('tickets')->orderBy('name')->get();

        return view('admin.departments.index', ['departments' => $departments, 'department' => new Department(['active' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $department = Department::create($this->validated($request));
        Activity::record("Added department {$department->name}", $department);

        return redirect()->route('admin.departments.index')->with('status', 'Department added.');
    }

    public function edit(Department $department): View
    {
        return view('admin.departments.edit', compact('department'));
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        $department->update($this->validated($request, $department));
        // Waiting tickets follow the new response times.
        if ($department->wasChanged('sla_hours')) {
            $department->tickets()->awaitingStaff()->each(fn (Ticket $ticket) => $ticket->forceFill(['response_due_at' => $ticket->responseDueAt()])->saveQuietly());
        }
        Activity::record("Updated department {$department->name}", $department, ['changed' => array_keys($department->getChanges())]);

        return redirect()->route('admin.departments.index')->with('status', 'Department updated.');
    }

    private function validated(Request $request, ?Department $department = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('departments')->ignore($department)],
            'sla_hours' => ['array'],
            'sla_hours.*' => ['nullable', 'numeric', 'min:0.1', 'max:8760'],
        ]);

        $sla = collect(TicketPriority::cases())
            ->mapWithKeys(fn (TicketPriority $priority) => [$priority->value => $data['sla_hours'][$priority->value] ?? null])
            ->filter(fn ($hours) => filled($hours))
            ->map(fn ($hours) => (float) $hours);

        return [...$data, 'sla_hours' => $sla->isEmpty() ? null : $sla->all(), 'active' => $request->boolean('active')];
    }
}
