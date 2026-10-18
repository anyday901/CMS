<?php

namespace App\Http\Controllers\Portal;

use App\Billing\AccountStatement;
use App\Billing\StatementPdf;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** The client's account statement for dates they pick, on the page and as a PDF. */
class StatementController extends Controller
{
    /** The longest range a statement covers, as with the admin reports. */
    private const MAX_YEARS = 25;

    public function show(Request $request): View
    {
        return view('portal.statement', ['statement' => $this->statement($request)]);
    }

    public function pdf(Request $request, StatementPdf $pdf): Response
    {
        return $pdf->download($this->statement($request));
    }

    /** This year so far unless the client picks dates; dates that don't parse are ignored. */
    private function statement(Request $request): AccountStatement
    {
        $today = CarbonImmutable::today();
        $from = self::date($request->query('from')) ?? $today->startOfYear();
        $to = self::date($request->query('to')) ?? $today;

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->lessThan($to->subYears(self::MAX_YEARS))) {
            $from = $to->subYears(self::MAX_YEARS);
        }

        return AccountStatement::for($request->user('client'), $from, $to);
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
