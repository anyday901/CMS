<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Import\Whmcs\ImportException;
use App\Import\Whmcs\ImportWizard;
use App\Import\Whmcs\WhmcsImporter;
use App\Models\Activity;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The step-by-step WHMCS import under Settings. */
class WhmcsImportController extends Controller
{
    private const SHOW = 'admin.whmcs-import.show';

    private const STEP = 'step';

    /** A connection detail, needed when it's typed in rather than read from .env. */
    private const DETAIL = ['required_if:source,form', 'nullable', 'string', 'max:255'];

    public function show(Request $request, ImportWizard $wizard): View
    {
        $reachable = $wizard->reachable();
        // A fresh walkthrough opens on the checklist; later visits go back to where it was left.
        $default = $reachable === ImportWizard::CONNECT ? ImportWizard::PREPARE : $reachable;
        $step = $reachable === ImportWizard::FINISH ? $reachable : min(max((int) $request->query(self::STEP, $default), 1), $reachable);

        return view('admin.whmcs-import.show', [
            'wizard' => $wizard,
            self::STEP => $step,
            'reachable' => $reachable,
            'blockers' => WhmcsImporter::blockers(),
        ]);
    }

    public function connect(Request $request, ImportWizard $wizard): RedirectResponse
    {
        $details = $request->validate([
            'source' => ['required', Rule::in(['env', 'form'])],
            'driver' => [...self::DETAIL, Rule::in(['mysql', 'mariadb'])],
            'host' => self::DETAIL,
            'port' => ['required_if:source,form', 'nullable', 'integer', 'between:1,65535'],
            'database' => self::DETAIL,
            'username' => self::DETAIL,
            'password' => array_slice(self::DETAIL, 1),
            'attachments' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->attempt(fn () => $wizard->connect($details), ImportWizard::DRY_RUN, 'Connected to WHMCS.');
    }

    public function dryRun(ImportWizard $wizard): RedirectResponse
    {
        return $this->attempt(fn () => $wizard->run(dryRun: true), ImportWizard::DRY_RUN, 'Dry run finished. Nothing was saved.');
    }

    public function import(Request $request, ImportWizard $wizard): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Tick the box to confirm you are ready to import.']);

        return $this->attempt(function () use ($wizard) {
            $report = $wizard->run(dryRun: false);
            Activity::record('Imported from WHMCS', null, ['counts' => $report->counts(), 'mismatches' => count($report->mismatches())]);
        }, ImportWizard::FINISH, 'Imported from WHMCS.');
    }

    /** Downloads the full Markdown report of the dry run or the import. */
    public function report(ImportWizard $wizard, string $run): BinaryFileResponse
    {
        $path = $wizard->summary($run)['path'] ?? null;
        abort_unless($path !== null && is_file($path), 404);

        return response()->download($path, basename($path), ['Content-Type' => 'text/markdown']);
    }

    public function reset(ImportWizard $wizard): RedirectResponse
    {
        $wizard->reset();

        return redirect()->route(self::SHOW);
    }

    private function attempt(callable $action, int $next, string $status): RedirectResponse
    {
        try {
            $action();
        } catch (ImportException|QueryException $e) {
            return back()->withInput(request()->except('password'))->withErrors(['whmcs' => $e->getMessage()]);
        }

        return redirect()->route(self::SHOW, [self::STEP => $next])->with('status', $status);
    }
}
