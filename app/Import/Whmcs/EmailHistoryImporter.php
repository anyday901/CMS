<?php

namespace App\Import\Whmcs;

use App\Models\EmailLog;
use App\Models\Setting;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

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

    /**
     * @param  array<int, mixed>  $clients  the clients here, by WHMCS id
     * @param  array<int, string>|null  $emails  when adding to an earlier import, each client's email here, to check it is
     *                                           the same person in WHMCS: both may have given the same id to someone new since
     */
    public function __construct(private ImportRun $run, private array $clients, private ?array $emails = null) {}

    /**
     * Brings over only these emails, into an install already imported from
     * WHMCS, for imports made before emails came over too. Emails already
     * here are skipped, so it can run any number of times.
     *
     * @throws ImportException when this install wasn't imported from WHMCS
     */
    public static function bringOver(ConnectionInterface $source, bool $dryRun): ImportReport
    {
        if (! Setting::whereKey(WhmcsImporter::SETTING)->exists()) {
            throw new ImportException('This install wasn\'t imported from WHMCS, so there are no WHMCS clients to add emails to. Run the full import instead.');
        }

        $run = new ImportRun($source, new ImportReport($dryRun));
        $lastClient = json_decode((string) Setting::find(WhmcsImporter::SETTING)?->value, true)['last_client_id'] ?? null;
        // Clients keep their WHMCS ids. Anonymised ones had their emails deleted on purpose, so they get none back.
        $emails = DB::table('clients')
            ->whereNull('anonymised_at')
            ->when($lastClient !== null, fn ($query) => $query->where('id', '<=', $lastClient))
            ->pluck('email', 'id')
            ->map(fn ($email) => Str::lower(trim((string) $email)))
            ->all();

        DB::beginTransaction();

        try {
            (new self($run, $emails, $emails))->import();
            $run->flushAll();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $dryRun ? DB::rollBack() : DB::commit();

        return $run->report;
    }

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

                $clientEmail = Str::lower(trim((string) ($addresses[$row->userid] ?? '')));
                $missing = $this->missing((int) $row->userid, $clientEmail);

                if ($missing !== null) {
                    $this->run->report->skipped(self::SECTION, "Email {$row->id} (\"".Whmcs::short($row->subject ?? '', 80)."\") was to client {$row->userid}, {$missing}.");

                    continue;
                }

                $this->queue($row, $clientEmail);
            }
        });

        if ($already > 0) {
            $this->run->report->note(self::SECTION, number_format($already).' '.Str::plural('email', $already).' came over in an earlier import and were left as they are.');
        }
    }

    /** Why an email's client doesn't get it here, or null when they do. */
    private function missing(int $client, string $whmcsEmail): ?string
    {
        if (! isset($this->clients[$client])) {
            return "who isn't here";
        }

        if ($this->emails !== null && $this->emails[$client] !== $whmcsEmail) {
            return "whose email in WHMCS ({$whmcsEmail}) isn't the one here ({$this->emails[$client]}), so it may be someone else";
        }

        return null;
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
            // Blind copies stay out: Send again would show them to everyone as copies.
            'cc' => $this->copies($row->cc ?? ''),
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

    /** WHMCS keeps copies as comma-separated addresses; here they are a list. */
    private function copies(mixed $cc): ?string
    {
        $addresses = array_values(array_unique(array_filter(array_map('trim', explode(',', Whmcs::text($cc))))));

        return $addresses === [] ? null : json_encode($addresses);
    }
}
