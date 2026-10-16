<?php

namespace App\Http\Controllers;

use App\System\HealthCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /health, for uptime monitors: 200 while nothing has a problem, 503
 * otherwise. With ?token= set to HEALTH_TOKEN it lists each check's status.
 */
class HealthController extends Controller
{
    public function __invoke(Request $request, HealthCheck $health): JsonResponse
    {
        $checks = $health->run(checkUpdates: false);
        $overall = HealthCheck::overall($checks);
        $token = (string) config('app.health_token');
        $detailed = $token !== '' && hash_equals($token, (string) $request->query('token'));

        return response()->json(array_filter([
            'status' => $overall,
            'checks' => $detailed ? collect($checks)->mapWithKeys(fn (array $check) => [$check['key'] => $check['status']])->all() : null,
        ]), $overall === HealthCheck::PROBLEM ? 503 : 200);
    }
}
