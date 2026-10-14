<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Department;
use App\Models\EscalationRule;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Ticket escalation rules, run every five minutes by tickets:escalate. */
class EscalationRuleController extends Controller
{
    public function index(): View
    {
        return view('admin.escalation-rules.index', [
            'rules' => EscalationRule::ordered()->with(['setDepartment', 'assignee'])->get(),
            'departments' => Department::orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.escalation-rules.form', ['rule' => new EscalationRule(['active' => true])] + $this->choices());
    }

    public function store(Request $request): RedirectResponse
    {
        $rule = EscalationRule::create($this->validated($request));
        Activity::record("Added escalation rule {$rule->name}", $rule);

        return redirect()->route('admin.escalation-rules.index')->with('status', 'Escalation rule added.');
    }

    public function edit(EscalationRule $escalationRule): View
    {
        return view('admin.escalation-rules.form', ['rule' => $escalationRule] + $this->choices());
    }

    public function update(Request $request, EscalationRule $escalationRule): RedirectResponse
    {
        $escalationRule->update($this->validated($request));
        Activity::record("Updated escalation rule {$escalationRule->name}", $escalationRule);

        return redirect()->route('admin.escalation-rules.index')->with('status', 'Escalation rule saved.');
    }

    public function destroy(EscalationRule $escalationRule): RedirectResponse
    {
        $escalationRule->delete();
        Activity::record("Deleted escalation rule {$escalationRule->name}");

        return redirect()->route('admin.escalation-rules.index')->with('status', 'Escalation rule deleted.');
    }

    private function choices(): array
    {
        return [
            'departments' => Department::orderBy('name')->get(),
            'staff' => User::orderBy('name')->get()->filter(fn (User $user) => $user->allows('manage-tickets')),
        ];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'department_ids' => ['array'],
            'department_ids.*' => ['integer', 'exists:departments,id'],
            'statuses' => ['array'],
            'statuses.*' => [Rule::in(array_column(array_filter(TicketStatus::cases(), fn ($status) => $status !== TicketStatus::Closed), 'value'))],
            'priorities' => ['array'],
            'priorities.*' => [Rule::enum(TicketPriority::class)],
            'minutes_since_reply' => ['nullable', 'integer', 'min:1', 'max:525600'],
            'set_department_id' => ['nullable', 'exists:departments,id'],
            'set_status' => ['nullable', Rule::enum(TicketStatus::class)],
            'set_priority' => ['nullable', Rule::enum(TicketPriority::class)],
            'assign_to' => ['nullable', 'exists:users,id'],
            'notify_user_ids' => ['array'],
            'notify_user_ids.*' => ['integer', 'exists:users,id'],
            'reply' => ['nullable', 'string', 'max:20000'],
        ]);

        $rule = [
            ...array_fill_keys(['minutes_since_reply', 'set_department_id', 'set_status', 'set_priority', 'assign_to', 'reply'], null),
            ...$data,
            'department_ids' => array_map('intval', $data['department_ids'] ?? []) ?: null,
            'statuses' => ($data['statuses'] ?? []) ?: null,
            'priorities' => ($data['priorities'] ?? []) ?: null,
            'notify_user_ids' => array_map('intval', $data['notify_user_ids'] ?? []) ?: null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'active' => $request->boolean('active'),
            'sla_breached' => $request->boolean('sla_breached'),
            'notify_assignee' => $request->boolean('notify_assignee'),
        ];

        if (! $rule['minutes_since_reply'] && ! $rule['sla_breached']) {
            throw ValidationException::withMessages(['minutes_since_reply' => 'Set how long since the last reply, or tick "response overdue", so the rule doesn\'t match every ticket at once.']);
        }

        if (array_filter(Arr::only($rule, ['set_department_id', 'set_status', 'set_priority', 'assign_to', 'notify_assignee', 'notify_user_ids', 'reply']), fn ($value) => $value !== false && filled($value)) === []) {
            throw ValidationException::withMessages(['name' => 'Choose at least one thing for the rule to do.']);
        }

        return $rule;
    }
}
