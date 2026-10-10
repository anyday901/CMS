<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivityController extends Controller
{
    public function __invoke(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $actor = $request->query('actor');

        $entries = Activity::query()
            ->with('client')
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('description', 'like', "%{$search}%")
                ->orWhere('actor_name', 'like', "%{$search}%")
                ->orWhere('ip_address', $search)))
            ->when(in_array($actor, ['staff', 'client', 'system'], true), fn ($query) => $query->where('actor_type', $actor))
            ->latest('created_at')
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.activity.index', compact('entries', 'search', 'actor'));
    }
}
