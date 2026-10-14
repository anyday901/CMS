{{-- $ticket: when staff should answer under the department's SLA. --}}
@if ($ticket->response_due_at === null)
    <span class="text-ink-400">—</span>
@elseif ($ticket->responseOverdue())
    <span class="font-semibold text-red-700" title="Due {{ $ticket->response_due_at->format('M j, Y g:ia') }}">Overdue {{ $ticket->response_due_at->diffForHumans(syntax: Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true) }}</span>
@else
    <span class="text-ink-700" title="Due {{ $ticket->response_due_at->format('M j, Y g:ia') }}">In {{ $ticket->response_due_at->diffForHumans(syntax: Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true) }}</span>
@endif
