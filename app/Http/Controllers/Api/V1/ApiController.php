<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\Request;

/** Shared by the REST API's controllers (see docs/api.md). */
abstract class ApiController extends Controller
{
    /** Lists return 25 records a page unless per_page asks for up to 100. */
    protected function perPage(Request $request): int
    {
        $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return $request->integer('per_page', 25) ?: 25;
    }

    protected function key(): ApiKey
    {
        return ApiKey::current() ?? abort(401);
    }
}
