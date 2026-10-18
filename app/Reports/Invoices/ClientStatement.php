<?php

namespace App\Reports\Invoices;

use App\Billing\AccountStatement;
use App\Models\Client;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Reports\ReportTable;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * One client's invoices and payments in date order, with the balance after
 * each, and the changes to their credit balance. Clients see the same
 * AccountStatement on the portal's statement page.
 */
class ClientStatement extends Report
{
    private const DATE_FORMAT = 'M j, Y';

    private const TYPES = [
        AccountStatement::INVOICE => 'Invoice',
        AccountStatement::PAYMENT => 'Payment',
        AccountStatement::REFUND => 'Refund',
        AccountStatement::CREDIT_APPLIED => 'Credit applied',
        AccountStatement::CREDIT_NOTE => 'Credit note',
    ];

    public function key(): string
    {
        return 'client-statement';
    }

    public function title(): string
    {
        return 'Client statement';
    }

    public function group(): string
    {
        return ReportRegistry::INVOICES;
    }

    public function description(): string
    {
        return 'One client\'s invoices, payments and refunds in date order, with what they owed after each.';
    }

    public function filters(): array
    {
        return [self::DATES, self::CLIENT];
    }

    public function defaultRange(CarbonImmutable $today): array
    {
        return [$today->startOfYear(), $today];
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $client = $filters->client;

        if ($client === null) {
            return (new ReportResult)->note('Enter a client\'s number or email address to see their statement.');
        }

        $statement = AccountStatement::for($client, $filters->from, $filters->to);

        $table = new ReportTable([
            'date' => ['Date', ReportTable::DATE],
            'type' => ['Type', ReportTable::TEXT],
            'reference' => ['Invoice', ReportTable::TEXT],
            'description' => ['Details', ReportTable::TEXT],
            'debit' => ['Charged', ReportTable::MONEY],
            'credit' => ['Paid', ReportTable::MONEY],
            'balance' => ['Balance', ReportTable::MONEY],
        ], $client->fullName(), $client->currency);
        $table->empty = 'No invoices or payments in these dates.';

        foreach ($statement->lines as $line) {
            $table->add($this->row($line));
        }
        $table->withTotals(['date' => null, 'type' => 'Total', 'balance' => $statement->closing]);

        $credit = new ReportTable([
            'date' => ['Date', ReportTable::DATE],
            'description' => ['Details', ReportTable::TEXT],
            'amount' => ['Change', ReportTable::MONEY],
            'balance' => ['Credit balance', ReportTable::MONEY],
        ], 'Credit balance', $client->currency);
        $credit->empty = 'No changes to the credit balance in these dates.';

        foreach ($statement->creditEntries as $entry) {
            $credit->add([
                'date' => $entry['date'],
                'description' => $entry['description'],
                'amount' => $entry['amount'],
                'balance' => $entry['balance'],
                '_links' => $entry['invoice_id'] ? ['description' => route('admin.invoices.show', $entry['invoice_id'])] : [],
            ]);
        }

        $from = $statement->from->format(self::DATE_FORMAT);
        $to = $statement->to->format(self::DATE_FORMAT);

        return (new ReportResult)->table($table)->table($credit)
            ->note("Balance brought forward from before {$from}: ".Money::format($statement->opening, $client->currency).'. Balance is what the client owed after each line; below zero, they had paid ahead.')
            ->note('Drafts and cancelled invoices aren\'t included. Payments from the credit balance show as Credit applied.')
            ->note("Credit balance before {$from}: ".Money::format($statement->creditOpening, $client->currency)."; at the end of {$to}: ".Money::format($statement->creditClosing, $client->currency).'. Credit isn\'t counted in the balance until it is applied to an invoice.');
    }

    public static function label(Client $client): string
    {
        return "#{$client->id} {$client->fullName()} ({$client->email})";
    }

    /**
     * One statement line as a table row, linked to its invoice.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function row(array $line): array
    {
        $description = match ($line['type']) {
            AccountStatement::INVOICE => 'Due '.CarbonImmutable::parse($line['due_date'])->format(self::DATE_FORMAT),
            AccountStatement::CREDIT_APPLIED => 'From credit balance',
            AccountStatement::CREDIT_NOTE => "{$line['reference']}: {$line['method']}",
            default => ucfirst((string) $line['gateway']).($line['reference'] ? " {$line['reference']}" : ''),
        };

        return [
            'date' => $line['date'],
            'type' => self::TYPES[$line['type']],
            'reference' => $line['invoice_id'] ? "#{$line['invoice_number']}" : '',
            'description' => $description,
            'debit' => $line['debit'],
            'credit' => $line['credit'],
            'balance' => $line['balance'],
            '_links' => $line['invoice_id'] ? ['reference' => route('admin.invoices.show', $line['invoice_id'])] : [],
        ];
    }
}
