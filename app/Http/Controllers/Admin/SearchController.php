<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\GlobalSearch;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The search box in the admin menu: clients, services and domains, invoices, orders and tickets at once. */
class SearchController extends Controller
{
    public function __invoke(Request $request): View
    {
        $query = trim((string) $request->query('q'));
        $search = new GlobalSearch($query);

        return view('admin.search', [
            'query' => $query,
            'tooShort' => $search->tooShort(),
            'results' => $search->results($request->user()),
        ]);
    }
}
