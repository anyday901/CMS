<?php

namespace App\Addons\ProjectManagement;

use App\Addons\ProjectManagement\Models\Project;
use App\Addons\ProjectManagement\Models\TimeEntry;
use App\Billing\InvoiceEditor;
use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Puts a project's unbilled time on a draft invoice for its client, one
 * line per entry, through the usual invoice editor so tax and totals are
 * worked out as for any invoice. Staff review and publish it from there.
 */
class ProjectInvoicer
{
    /** How long the client has to pay, counted from today. Staff can change it on the draft. */
    public const DUE_DAYS = 7;

    public const DEFAULT_LINE = '{project}: {description} ({date}, {time} at {rate}/hr)';

    public function __construct(private InvoiceEditor $editor) {}

    /** @throws InvalidArgumentException when there is nothing to bill */
    public function invoice(Project $project): Invoice
    {
        $client = $project->client ?? throw new InvalidArgumentException('Only a client\'s project can be invoiced. Pick a client for this project first.');
        $addon = app(ProjectManagementAddon::class);

        $invoice = DB::transaction(function () use ($project, $client, $addon) {
            $entries = $project->timeEntries()->unbilled()->with(['task', 'user'])
                ->orderBy('worked_on')->orderBy('id')->lockForUpdate()->get()
                ->filter(fn (TimeEntry $entry) => $entry->amount() > 0)->values();

            if ($entries->isEmpty()) {
                throw new InvalidArgumentException('There\'s no unbilled billable time with a rate on this project.');
            }

            $taxable = (bool) $addon->setting('taxable', true);
            $invoice = $this->editor->create($client, [
                'issue_date' => today()->toDateString(),
                'due_date' => today()->addDays(self::DUE_DAYS)->toDateString(),
                'notes' => null,
                'items' => $entries->map(fn (TimeEntry $entry) => [
                    'description' => $this->line($project, $entry, (string) $addon->setting('invoice_line', self::DEFAULT_LINE)),
                    'amount' => $entry->amount(),
                    'taxable' => $taxable,
                ])->all(),
            ], draft: true);

            // Lines are created in the same order as the entries.
            foreach ($invoice->items()->orderBy('id')->get() as $index => $item) {
                $entries[$index]->forceFill(['invoice_id' => $invoice->id, 'invoice_item_id' => $item->id])->save();
            }
            $project->invoices()->syncWithoutDetaching([$invoice->id]);

            return $invoice;
        });

        ProjectHooks::invoiced($project, $invoice, $invoice->items()->count());

        return $invoice;
    }

    /** The invoice line for one entry, from the wording set under the add-on's settings. */
    public function line(Project $project, TimeEntry $entry, string $wording): string
    {
        $description = $entry->description ?: $entry->task?->title ?: 'Work';

        return Str::limit(strtr($wording ?: self::DEFAULT_LINE, [
            '{project}' => $project->title,
            '{task}' => $entry->task?->title ?? '',
            '{description}' => $description,
            '{date}' => $entry->worked_on->format('M j, Y'),
            '{time}' => Duration::format($entry->minutes),
            '{rate}' => Money::format($entry->rate, $project->currency()),
            '{staff}' => $entry->user?->name ?? '',
        ]), 255, '');
    }
}
