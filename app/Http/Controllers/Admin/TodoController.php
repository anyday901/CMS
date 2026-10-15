<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The staff to-do list: things to do, for someone or anyone, by a date, optionally about a client. */
class TodoController extends Controller
{
    /** Who the list shows: the signed-in staff member's, everyone's, or no one's. */
    private const WHO = ['mine', 'all', 'unassigned'];

    /** Which items: not done yet, overdue, done, or every one. */
    private const SHOW = ['unfinished', 'overdue', 'done', 'all'];

    public function index(Request $request): View
    {
        $who = in_array($request->query('who'), self::WHO, true) ? $request->query('who') : 'mine';
        $show = in_array($request->query('show'), self::SHOW, true) ? $request->query('show') : 'unfinished';

        $todos = Todo::with(['user', 'client', 'creator'])
            ->when($who === 'mine', fn ($query) => $query->where('user_id', $request->user()->id))
            ->when($who === 'unassigned', fn ($query) => $query->whereNull('user_id'))
            ->when($show === 'unfinished', fn ($query) => $query->unfinished())
            ->when($show === 'overdue', fn ($query) => $query->overdue())
            ->when($show === 'done', fn ($query) => $query->where('status', Todo::DONE))
            ->when($show === 'done', fn ($query) => $query->latest('completed_at'), fn ($query) => $query->orderByRaw('due_on is null')->orderBy('due_on'))
            ->latest('id')
            ->paginate(50)->withQueryString();

        return view('admin.todos.index', [
            'todos' => $todos,
            'who' => $who,
            'show' => $show,
            'todo' => new Todo(['user_id' => $request->user()->id, 'client_id' => $request->integer('client') ?: null]),
            'staff' => User::orderBy('name')->get(),
            'clientName' => $request->integer('client') ? Client::find($request->integer('client'))?->fullName() : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $todo = Todo::make($this->validated($request));
        $todo->creator()->associate($request->user());
        $todo->save();
        Activity::record("Added to-do \"{$todo->title}\"", $todo);

        return $this->back($request, $todo, 'Added to the to-do list.');
    }

    public function edit(Todo $todo): View
    {
        return view('admin.todos.edit', ['todo' => $todo, 'staff' => User::orderBy('name')->get()]);
    }

    public function update(Request $request, Todo $todo): RedirectResponse
    {
        $todo->update($this->validated($request));
        Activity::record("Updated to-do \"{$todo->title}\"", $todo);

        return $this->back($request, $todo, 'To-do saved.');
    }

    /** One click from a list: done, or back to do. */
    public function status(Request $request, Todo $todo): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(Todo::STATUSES))]]);
        $todo->update($data);
        Activity::record("Marked to-do \"{$todo->title}\" as ".strtolower($todo->statusLabel()), $todo);

        return back()->with('status', $todo->isDone() ? 'Done. Nice work.' : 'To-do updated.');
    }

    public function destroy(Request $request, Todo $todo): RedirectResponse
    {
        $todo->delete();
        Activity::record("Deleted to-do \"{$todo->title}\"", null, [], $todo->client);

        return $this->back($request, $todo, 'To-do deleted.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['nullable', Rule::in(array_keys(Todo::STATUSES))],
            'due_on' => ['nullable', 'date'],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],
        ]);

        return ['status' => $data['status'] ?? Todo::OPEN] + $data;
    }

    /** Back to the client the to-do is about when it was added there, otherwise to the list. */
    private function back(Request $request, Todo $todo, string $status): RedirectResponse
    {
        $to = $request->input('from') === 'client' && $todo->client_id
            ? route('admin.clients.show', $todo->client_id).'#todos'
            : route('admin.todos.index');

        return redirect()->to($to)->with('status', $status);
    }
}
