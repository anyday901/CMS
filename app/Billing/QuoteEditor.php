<?php

namespace App\Billing;

use App\Enums\QuoteStage;
use App\Mail\ClientMailer;
use App\Models\Activity;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Quote;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates, edits, copies and sends quotes. A quote can be changed until it
 * has become an invoice; after that the invoice is what counts.
 */
class QuoteEditor
{
    public function __construct(private ClientMailer $mailer) {}

    /**
     * @param  array{subject: string, stage?: string, issue_date: string, valid_until: string, notes: ?string, admin_notes: ?string, items: list<array{id?: ?int, description: string, amount: int, taxable: bool}>}  $data
     */
    public function create(Client $client, array $data): Quote
    {
        $quote = DB::transaction(function () use ($client, $data) {
            $quote = Quote::create([
                'client_id' => $client->id,
                'currency' => $client->currency,
                'stage' => $data['stage'] ?? QuoteStage::Draft->value,
            ] + $this->quoteAttributes($data));

            foreach ($data['items'] as $item) {
                $quote->items()->create($this->itemAttributes($item));
            }

            return $this->stamp($this->ensurePositive($quote->recalculate()));
        });

        Activity::record("Created quote #{$quote->number} for ".Money::format($quote->total, $quote->currency), $quote);

        return $quote->refresh();
    }

    /** @param  array{subject: string, stage: string, issue_date: string, valid_until: string, notes: ?string, admin_notes: ?string, items: list<array{id?: ?int, description: string, amount: int, taxable: bool}>}  $data */
    public function update(Quote $quote, array $data): Quote
    {
        $quote = DB::transaction(function () use ($quote, $data) {
            $quote = Quote::lockForUpdate()->findOrFail($quote->id);

            if (! $quote->isEditable()) {
                throw new InvalidArgumentException("Quote #{$quote->number} is already invoice #{$quote->invoice?->number} and can't be edited.");
            }

            $quote->update(['stage' => $data['stage']] + $this->quoteAttributes($data));

            $keep = collect($data['items'])->pluck('id')->filter()->all();
            $quote->items()->whereNotIn('id', $keep)->delete();

            foreach ($data['items'] as $item) {
                $attributes = $this->itemAttributes($item);
                isset($item['id'])
                    ? $quote->items()->whereKey($item['id'])->update($attributes)
                    : $quote->items()->create($attributes);
            }

            return $this->stamp($this->ensurePositive($quote->recalculate()));
        });

        Activity::record("Edited quote #{$quote->number}, total now ".Money::format($quote->total, $quote->currency).", stage {$quote->stage->label()}", $quote);

        return $quote;
    }

    /** A new draft with the same lines and wording, valid for as long as the original was. */
    public function duplicate(Quote $original): Quote
    {
        $copy = DB::transaction(function () use ($original) {
            $days = max(1, (int) $original->issue_date->diffInDays($original->valid_until));
            $copy = Quote::create([
                'client_id' => $original->client_id,
                'currency' => $original->currency,
                'subject' => $original->subject,
                'stage' => QuoteStage::Draft,
                'issue_date' => today(),
                'valid_until' => today()->addDays($days),
                'notes' => $original->notes,
                'admin_notes' => $original->admin_notes,
            ]);

            foreach ($original->items()->orderBy('id')->get() as $item) {
                $copy->items()->create($item->only(['description', 'amount', 'taxable']));
            }

            return $copy->recalculate();
        });

        Activity::record("Copied quote #{$original->number} to new quote #{$copy->number}", $copy);

        return $copy;
    }

    /**
     * Emails the quote to the client with its PDF, and marks it delivered.
     * Returns null when the email template is off or the client has no
     * email address; the quote is still marked delivered then.
     */
    public function send(Quote $quote): ?EmailLog
    {
        if (! $quote->isEditable()) {
            throw new InvalidArgumentException("Quote #{$quote->number} has already been accepted.");
        }

        $quote->stage = QuoteStage::Delivered;
        $this->stamp($quote);

        $email = $this->mailer->quote($quote);

        Activity::record("Sent quote #{$quote->number} to the client".($email ? '' : ' (no email went out: the template is off or the client has no email address)'), $quote);

        return $email;
    }

    public function delete(Quote $quote): void
    {
        $quote->delete();

        Activity::record("Deleted quote #{$quote->number} ({$quote->subject})", $quote);
    }

    /** The first time a quote reaches the client, and when it was accepted. */
    private function stamp(Quote $quote): Quote
    {
        if (in_array($quote->stage->value, QuoteStage::VISIBLE, true) && $quote->delivered_at === null) {
            $quote->delivered_at = now();
        }

        if ($quote->stage === QuoteStage::Accepted && $quote->accepted_at === null) {
            $quote->accepted_at = now();
        }

        $quote->save();

        return $quote;
    }

    private function ensurePositive(Quote $quote): Quote
    {
        if ($quote->total <= 0) {
            throw new InvalidArgumentException('The quote total must be more than zero.');
        }

        return $quote;
    }

    private function quoteAttributes(array $data): array
    {
        return [
            'subject' => $data['subject'],
            'issue_date' => $data['issue_date'],
            'valid_until' => $data['valid_until'],
            'notes' => $data['notes'] ?? null,
            'admin_notes' => $data['admin_notes'] ?? null,
        ];
    }

    private function itemAttributes(array $item): array
    {
        return [
            'description' => $item['description'],
            'amount' => $item['amount'],
            'taxable' => $item['taxable'],
        ];
    }
}
