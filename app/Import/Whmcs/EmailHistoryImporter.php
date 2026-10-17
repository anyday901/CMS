<?php

namespace App\Import\Whmcs;

use App\Models\EmailLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The emails WHMCS sent to clients (tblemails), so each client's email list
 * goes back past the switch. They come over as sent emails, with the HTML
 * body turned into Markdown like the rest of the app's emails. Each keeps
 * its WHMCS id, so running this again only adds the ones not here yet.
 */
class EmailHistoryImporter
{
    public const SECTION = 'Emails';

    /** The template name of an email brought over from WHMCS. */
    public const TEMPLATE = 'whmcs';

    /** @param  array<int, mixed>  $clients  the clients here, by WHMCS id */
    public function __construct(private ImportRun $run, private array $clients) {}

    public function import(): void
    {
        if (! $this->run->hasTables('tblemails')) {
            return;
        }

        $already = 0;
        $this->run->chunk('tblemails', function (Collection $rows) use (&$already) {
            $here = DB::table('email_logs')->whereIn('whmcs_id', $rows->pluck('id'))->pluck('whmcs_id')->map(fn ($id) => (int) $id)->flip();
            $addresses = $this->run->table('tblclients')->whereIn('id', $rows->pluck('userid')->unique())->pluck('email', 'id');

            foreach ($rows as $row) {
                $this->run->report->found(self::SECTION);

                if (isset($here[(int) $row->id])) {
                    $already++;

                    continue;
                }

                if (! isset($this->clients[(int) $row->userid])) {
                    $this->run->report->skipped(self::SECTION, "Email {$row->id} (\"".Whmcs::short($row->subject ?? '', 80)."\") was to client {$row->userid}, who isn't here.");

                    continue;
                }

                $this->queue($row, Str::lower((string) ($addresses[$row->userid] ?? '')));
            }
        });

        if ($already > 0) {
            $this->run->report->note(self::SECTION, number_format($already).' '.Str::plural('email', $already).' came over in an earlier import and were left as they are.');
        }
    }

    private function queue(object $row, string $clientEmail): void
    {
        $sent = Whmcs::dateTime($row->date ?? null) ?? Whmcs::dateTime($row->created_at ?? null) ?? $this->run->now;
        [$status, $error] = $this->status($row);

        $this->run->queue('email_logs', [
            'whmcs_id' => (int) $row->id,
            'client_id' => (int) $row->userid,
            'template' => self::TEMPLATE,
            'to' => Whmcs::short($row->to ?? '', 255) ?? Whmcs::short($clientEmail, 255) ?? '',
            'cc' => $this->copies($row->cc ?? '', $row->bcc ?? ''),
            'subject' => Whmcs::short($row->subject ?? '') ?? '(no subject)',
            'body' => HtmlToMarkdown::convert((string) ($row->message ?? '')),
            'status' => $status,
            'error' => $error,
            'sent_at' => $status === EmailLog::SENT ? $sent : null,
            'created_at' => $sent,
            'updated_at' => $sent,
        ]);
        $this->run->report->imported(self::SECTION);
    }

    /** @return array{0: string, 1: string|null} the status here and why it wasn't sent */
    private function status(object $row): array
    {
        if (Whmcs::on($row->failed ?? '')) {
            return [EmailLog::FAILED, Whmcs::short($row->failure_reason ?? '', 1000) ?? 'WHMCS could not send this email.'];
        }

        if (Whmcs::on($row->pending ?? '')) {
            return [EmailLog::FAILED, 'Still waiting to go out in WHMCS when it was imported, so it was never sent.'];
        }

        return [EmailLog::SENT, null];
    }

    /** WHMCS keeps copies as comma-separated addresses; here they are one list, blind copies included. */
    private function copies(mixed $cc, mixed $bcc): ?string
    {
        $addresses = array_values(array_unique(array_filter(array_map('trim', explode(',', Whmcs::text($cc).','.Whmcs::text($bcc))))));

        return $addresses === [] ? null : json_encode($addresses);
    }
}
