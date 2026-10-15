<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\EmailTemplates;
use App\Models\Activity;
use App\Models\EmailTemplate;
use App\Models\EmailTemplateTranslation;
use App\Support\Language;
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

    public function edit(Request $request, string $key): View
    {
        abort_unless(array_key_exists($key, EmailTemplates::definitions()), 404);
        $locale = $this->locale($request);
        $template = EmailTemplate::find($key);

        return view('admin.email-templates.edit', [
            'key' => $key,
            'definition' => EmailTemplates::definition($key),
            'template' => $template,
            'locale' => $locale,
            'main' => Language::main(),
            'languages' => Language::all(),
            'wording' => EmailTemplates::wording($key, $locale, $template),
            'translated' => EmailTemplateTranslation::where('template', $key)->pluck('locale')->all(),
        ]);
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        abort_unless(array_key_exists($key, EmailTemplates::definitions()), 404);
        $locale = $this->locale($request);

        if ($locale !== Language::main()) {
            return $this->updateTranslation($request, $key, $locale);
        }

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

    /** Saves or resets the template in a language other than the main one. */
    private function updateTranslation(Request $request, string $key, string $locale): RedirectResponse
    {
        $back = redirect()->route('admin.email-templates.edit', ['key' => $key, 'locale' => $locale]);

        if ($request->boolean('reset')) {
            EmailTemplateTranslation::where('template', $key)->where('locale', $locale)->delete();
            Activity::record("Reset the {$locale} translation of the {$key} email template");

            return $back->with('status', 'Translation reset.');
        }

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
        ]);

        EmailTemplateTranslation::updateOrCreate(['template' => $key, 'locale' => $locale], $data);
        Activity::record("Updated the {$locale} translation of the {$key} email template");

        return $back->with('status', 'Translation saved. Clients who use '.Language::name($locale).' get it.');
    }

    private function locale(Request $request): string
    {
        $locale = (string) $request->input('locale', Language::main());

        return in_array($locale, Language::all(), true) ? $locale : Language::main();
    }
}
