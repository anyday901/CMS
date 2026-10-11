<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\EmailTemplates;
use App\Models\Activity;
use App\Models\EmailTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailTemplateController extends Controller
{
    public function index(): View
    {
        return view('admin.email-templates.index', [
            'definitions' => EmailTemplates::definitions(),
            'saved' => EmailTemplate::all()->keyBy('key'),
        ]);
    }

    public function edit(string $key): View
    {
        abort_unless(array_key_exists($key, EmailTemplates::definitions()), 404);

        return view('admin.email-templates.edit', [
            'key' => $key,
            'definition' => EmailTemplates::definition($key),
            'template' => EmailTemplate::find($key),
        ]);
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        abort_unless(array_key_exists($key, EmailTemplates::definitions()), 404);

        if ($request->boolean('reset')) {
            EmailTemplate::whereKey($key)->delete();
            Activity::record("Reset the {$key} email template to the default");

            return redirect()->route('admin.email-templates.index')->with('status', 'Template reset to the default.');
        }

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'enabled' => ['boolean'],
        ]);

        EmailTemplate::updateOrCreate(['key' => $key], ['subject' => $data['subject'], 'body' => $data['body'], 'enabled' => $request->boolean('enabled')]);
        Activity::record("Updated the {$key} email template", null, ['enabled' => $request->boolean('enabled')]);

        return redirect()->route('admin.email-templates.index')->with('status', 'Template saved.');
    }
}
