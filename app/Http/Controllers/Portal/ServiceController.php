<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\CustomField;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ServiceController extends Controller
{
    public function index(Request $request): View
    {
        $services = $request->user('client')->services()->with('product')->latest()->get();

        return view('portal.services.index', compact('services'));
    }

    public function show(Request $request, int $service): View
    {
        $service = $request->user('client')->services()->with('product')->findOrFail($service);

        return view('portal.services.show', [
            'service' => $service,
            'fields' => CustomField::forService($service->product_id)->where('admin_only', false)->get(),
        ]);
    }
}
