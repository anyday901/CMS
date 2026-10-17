<?php

namespace App\Import\Whmcs;

use App\Auth\LegacyPassword;
use App\Billing\CreditApplier;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\InvoiceItem;
use App\Models\ProductOption;
use App\Models\Setting;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Copies a WHMCS database into this app: servers and server groups, client
 * groups, clients and their contacts, staff notes on clients, the to-do list, products with prices, configurable options and module
 * settings, custom fields, services with their logins, invoices,
 * transactions, admins as staff, promotions, orders, the credit log,
 * cancellation requests, the activity log, the emails sent to clients,
 * affiliates with their referrals,
 * commissions and payouts, ticket departments, custom fields, tags,
 * tickets (guest tickets too), replies and feedback ratings, and the
 * knowledge base and announcements with their translations, network
 * issues, downloads and escalation rules.
 *
 * Clients, services, invoices and tickets keep their WHMCS ids, so invoice and
 * ticket numbers stay the same. That is why the import needs an empty install.
 * Everything runs in one database transaction: a dry run rolls it back, and a
 * failure part way leaves nothing behind.
 */
class WhmcsImporter
{
    /** Tables that must be empty before importing, since WHMCS ids are kept. */
    private const TARGET_TABLES = ['clients', 'products', 'services', 'invoices', 'tickets'];

    /** Settings key that remembers what was imported, for whmcs:compare. */
    public const SETTING = 'whmcs_import';

    private const CLIENTS = 'Clients';

    private const CONTACTS = 'Contacts';

    private const SERVICES = 'Services';

    private const INVOICES = 'Invoices';

    private const LINES = 'Invoice lines';

    private const TRANSACTIONS = 'Transactions';

    private const ADDONS = 'Service add-ons';

    private const DOMAINS = 'Domains';

    private ImportRun $run;

    private CatalogImporter $catalog;

    private ?TicketImporter $tickets = null;

    private ?SupportImporter $support = null;

    /** @var array<int, string> client id => currency code */
    private array $clients = [];

    /** @var array<int, array{cycle: BillingCycle, currency: string, product: int}> service id => what later rows need */
    private array $services = [];

    /** @var array<int, string> invoice id => currency code */
    private array $invoices = [];

    /** @var array<int, int> WHMCS tblaccounts id => local transaction id */
    private array $transactions = [];

    /** @var array<string, int> "gateway|reference" => times seen */
    private array $references = [];

    /** @var array<int, int> client id => WHMCS credit balance, for those with one */
    private array $balances = [];

    private ContactLoginImporter $logins;

    private ClientExtrasImporter $extras;

    private ServerImporter $servers;

    /** @param  string|null  $encryptionHash  WHMCS's cc_encryption_hash, to bring stored passwords over */
    public function __construct(private ?string $attachmentsPath = null, private ?string $encryptionHash = null, private ?string $downloadsPath = null) {}

    /** @throws ImportException when this install already has data */
    public function run(ConnectionInterface $source, bool $dryRun): ImportReport
    {
        $blockers = self::blockers();

        if ($blockers !== []) {
            throw new ImportException("This install already has {$blockers[0]}. Import into a new, empty install (run php artisan migrate:fresh first if it only has test data).");
        }

        $this->run = new ImportRun($source, new ImportReport($dryRun));
        DB::beginTransaction();

        try {
            $this->import();
            $this->run->flushAll();
            (new TotalsCheck($this->run, $this->invoices))->check();

            if (! $dryRun) {
                $this->remember();
            }
        } catch (Throwable $e) {
            DB::rollBack();
            $this->tickets?->removeCopiedFiles();
            $this->support?->removeCopiedFiles();

            throw $e;
        }

        $dryRun ? DB::rollBack() : DB::commit();

        return $this->run->report;
    }

    /** @return list<string> the tables that already have data here, which stop an import */
    public static function blockers(): array
    {
        return array_values(array_filter(self::TARGET_TABLES, fn (string $table) => DB::table($table)->exists()));
    }

    private function import(): void
    {
        $this->logins = new ContactLoginImporter($this->run, self::CONTACTS);
        $this->servers = new ServerImporter($this->run, filled($this->encryptionHash) ? new WhmcsCrypt($this->encryptionHash) : null);
        $this->servers->import();
        $this->catalog = new CatalogImporter($this->run, $this->servers);
        $this->catalog->import();
        $this->extras = new ClientExtrasImporter($this->run);
        $this->extras->importGroups();
        $this->importClients();
        $this->importContacts();
        $this->logins->importUsers($this->clients);
        $this->importServices();
        $this->importServiceOptions();
        $this->importInvoices();
        $this->importTransactions();
        $staff = new StaffImporter($this->run);
        $staff->import();
        $history = new HistoryImporter($this->run, $this->clients, $this->services, $this->invoices, $this->catalog->products);
        $history->importPromotions();
        $history->importOrders();
        $history->importCredit($this->balances);
        $history->importCancellations();
        $history->importActivity($staff);
        $this->extras->importNotes($this->clients, $staff);
        $this->extras->importTodos($staff);
        (new EmailHistoryImporter($this->run, $this->clients))->import();
        (new AffiliateImporter($this->run, $this->clients, $this->invoices))->import();
        $this->tickets = new TicketImporter($this->run, $this->clients, $staff, $this->attachmentsPath);
        $this->tickets->import();
        $this->support = new SupportImporter($this->run, $this->servers, $staff, $this->tickets->departments(), $this->catalog->products, $this->downloadsPath);
        $this->support->import();
        $this->reportNotImported();
    }

    /**
     * Things WHMCS bills that this app doesn't have yet: listed so nothing
     * recurring is lost without anyone noticing, and so staff can bill them
     * another way (whmcs:compare lists their renewals as WHMCS-only).
     */
    private function reportNotImported(): void
    {
        $billed = ['Active', 'Suspended', 'Pending'];
        $report = $this->run->report;

        if ($this->run->hasTables('tblhostingaddons')) {
            foreach ($this->run->table('tblhostingaddons')->whereIn('status', $billed)->orderBy('id')->cursor() as $addon) {
                $report->found(self::ADDONS);
                $report->skipped(self::ADDONS, "Add-on {$addon->id} (".Whmcs::text($addon->name ?? '').") on service {$addon->hostingid}, {$addon->recurring} {$addon->billingcycle}: add-ons aren't supported yet, so add it to the service's price or bill it by hand.");
            }
        }

        if ($this->run->hasTables('tbldomains')) {
            foreach ($this->run->table('tbldomains')->whereIn('status', $billed)->orderBy('id')->cursor() as $domain) {
                $report->found(self::DOMAINS);
                $report->skipped(self::DOMAINS, "Domain {$domain->domain} of client {$domain->userid}, {$domain->recurringamount} due {$domain->nextduedate}: domains aren't supported yet.");
            }
        }

        if (DB::table('tax_rules')->doesntExist() && $this->run->table('tblinvoices')->where('taxrate', '>', 0)->exists()) {
            $report->note(self::INVOICES, 'WHMCS charged tax on some invoices, but there are no tax rules here yet. Add them under Tax before the first billing run, or new invoices will have no tax.');
        }
    }

    private function importClients(): void
    {
        $passwords = $this->ownerPasswords();
        $emails = [];

        foreach ($this->run->table('tblclients')->orderBy('id')->cursor() as $row) {
            $this->run->report->found(self::CLIENTS);
            $email = strtolower(trim((string) $row->email));
            $problem = match (true) {
                $email === '' => "Client {$row->id} (".Whmcs::name($row).') has no email address.',
                isset($emails[$email]) => "Client {$row->id} has the same email as client {$emails[$email]} ({$email}). Merge them in WHMCS or change one address, then import again.",
                default => null,
            };

            if ($problem !== null) {
                $this->run->report->skipped(self::CLIENTS, $problem);

                continue;
            }

            $emails[$email] = (int) $row->id;
            $this->logins->claim($email);
            $client = $this->clientRow($row, $email, $passwords[(int) $row->id] ?? null);
            DB::table('clients')->insert($client);

            if ($client['credit_balance'] !== 0) {
                $this->balances[$client['id']] = $client['credit_balance'];
            }

            $this->clients[$client['id']] = $client['currency'];
            $this->run->report->imported(self::CLIENTS);
        }
    }

    /** @return array<string, mixed> */
    private function clientRow(object $row, string $email, ?string $ownerHash): array
    {
        $country = strtoupper(trim((string) ($row->country ?? '')));

        return [
            'id' => (int) $row->id,
            'first_name' => Whmcs::short($row->firstname) ?? '-',
            'last_name' => Whmcs::short($row->lastname) ?? '',
            'company' => Whmcs::short($row->companyname ?? ''),
            'email' => $email,
            // Clients who got WHMCS's emails count as having a working address.
            'email_verified_at' => $this->run->now,
            'password' => null,
            'legacy_password' => $this->passwordHash($row, $ownerHash),
            'phone' => Whmcs::short($row->phonenumber ?? ''),
            'address1' => Whmcs::short($row->address1 ?? ''),
            'address2' => Whmcs::short($row->address2 ?? ''),
            'city' => Whmcs::short($row->city ?? ''),
            'state' => Whmcs::short($row->state ?? ''),
            'postcode' => Whmcs::short($row->postcode ?? ''),
            'country' => strlen($country) === 2 ? $country : null,
            'currency' => $this->run->currency($row->currency ?? 0),
            'tax_exempt' => Whmcs::on($row->taxexempt ?? ''),
            'status' => Whmcs::clientStatus($row->status ?? '')->value,
            'client_group_id' => $this->extras->groupId($row->groupid ?? 0),
            // WHMCS 7.10 and later ask for marketing consent; older installs never did, so nobody counts as agreeing.
            'marketing_emails' => Whmcs::on($row->marketing_emails_opt_in ?? ''),
            'credit_balance' => Whmcs::cents($row->credit ?? 0),
            'notes' => Whmcs::text($row->notes ?? '') ?: null,
            'custom_fields' => Whmcs::fieldValues($this->catalog->clientValues[(int) $row->id] ?? []),
            'created_at' => Whmcs::dateTime($row->created_at ?? null) ?? Whmcs::dateTime($row->datecreated ?? null) ?? $this->run->now,
            'updated_at' => $this->run->now,
        ];
    }

    /** The client's WHMCS hash when it can be checked at login; older formats mean a password reset. */
    private function passwordHash(object $row, ?string $ownerHash): ?string
    {
        $hash = $ownerHash ?? (filled($row->password ?? null) ? (string) $row->password : null);

        if ($hash !== null && ! LegacyPassword::supported($hash)) {
            $this->run->report->note(self::CLIENTS, "Client {$row->id} has an old WHMCS password hash that can't be checked safely, so they'll need to use Forgot password.");

            return null;
        }

        return $hash;
    }

    /**
     * WHMCS 8 moved logins to tblusers. Each client's owner keeps their
     * password; the client's own email stays their login here.
     *
     * @return array<int, string> client id => password hash
     */
    private function ownerPasswords(): array
    {
        if (! $this->run->hasTables('tblusers', 'tblusers_clients')) {
            return [];
        }

        return $this->run->table('tblusers_clients')
            ->join('tblusers', 'tblusers.id', '=', 'tblusers_clients.auth_user_id')
            ->where('tblusers_clients.owner', 1)
            ->whereNotNull('tblusers.password')
            ->pluck('tblusers.password', 'tblusers_clients.client_id')
            ->mapWithKeys(fn ($hash, $clientId) => [(int) $clientId => (string) $hash])
            ->filter()
            ->all();
    }

    private function importContacts(): void
    {
        if (! $this->run->hasTables('tblcontacts')) {
            return;
        }

        foreach ($this->run->table('tblcontacts')->orderBy('id')->cursor() as $row) {
            $this->run->report->found(self::CONTACTS);
            $email = strtolower(trim((string) $row->email));

            if (! isset($this->clients[(int) $row->userid])) {
                $this->run->report->skipped(self::CONTACTS, "Contact {$row->id} (".Whmcs::name($row).") belongs to client {$row->userid}, which wasn't imported.");

                continue;
            }

            if ($email === '') {
                $this->run->report->skipped(self::CONTACTS, "Contact {$row->id} (".Whmcs::name($row).") of client {$row->userid} has no email address.");

                continue;
            }

            $access = $this->logins->access($row, $email);

            $this->run->queue('client_contacts', [
                'client_id' => (int) $row->userid,
                'first_name' => Whmcs::short($row->firstname) ?? '-',
                'last_name' => Whmcs::short($row->lastname) ?? '',
                'email' => $email,
                'phone' => Whmcs::short($row->phonenumber ?? '', 50),
                'invoice_emails' => Whmcs::on($row->invoiceemails ?? ''),
                'support_emails' => Whmcs::on($row->supportemails ?? ''),
                'portal_access' => $access !== null,
                'permissions' => $access === null ? null : json_encode($access),
                'created_at' => $this->run->now,
                'updated_at' => $this->run->now,
            ]);
            $this->run->report->imported(self::CONTACTS);
        }
    }

    private function importServices(): void
    {
        foreach ($this->run->table('tblhosting')->orderBy('id')->cursor() as $row) {
            $this->run->report->found(self::SERVICES);
            $product = $this->catalog->products[(int) $row->packageid] ?? null;
            $cycle = Whmcs::cycle($row->billingcycle);

            $problem = match (true) {
                ! isset($this->clients[(int) $row->userid]) => "belongs to client {$row->userid}, which wasn't imported",
                $product === null => "uses product {$row->packageid}, which wasn't found",
                $cycle === null => "has a billing cycle this app doesn't have ({$row->billingcycle})",
                default => null,
            };

            if ($problem !== null) {
                $this->run->report->skipped(self::SERVICES, "Service {$row->id} ({$row->domain}) {$problem}.");

                continue;
            }

            DB::table('services')->insert($this->serviceRow($row, $product['id'], $cycle) + $this->servers->login($row, $product['module']));
            $this->services[(int) $row->id] = ['cycle' => $cycle, 'currency' => $this->clients[(int) $row->userid], 'product' => (int) $row->packageid];
            $this->run->report->imported(self::SERVICES);
        }
    }

    /** @return array<string, mixed> */
    private function serviceRow(object $row, int $productId, BillingCycle $cycle): array
    {
        $status = Whmcs::serviceStatus($row->domainstatus);
        $suspended = $status === ServiceStatus::Suspended;
        $free = $cycle === BillingCycle::OneTime && strtolower(str_replace(' ', '', (string) $row->billingcycle)) === 'freeaccount';
        $registered = Whmcs::date($row->regdate) ?? $this->run->today();

        return [
            'id' => (int) $row->id,
            'client_id' => (int) $row->userid,
            'product_id' => $productId,
            'label' => Whmcs::short($row->domain ?? ''),
            'billing_cycle' => $cycle->value,
            'recurring_amount' => $free ? 0 : Whmcs::cents($row->amount),
            'status' => $status->value,
            'registration_date' => Whmcs::day($registered),
            'next_due_date' => $cycle->isRecurring() ? Whmcs::day(Whmcs::date($row->nextduedate)) : null,
            'suspended_at' => $suspended ? $this->run->now : null,
            'suspension_reason' => $suspended ? Whmcs::short($row->suspendreason ?? '') ?? 'Suspended in WHMCS' : null,
            'terminated_at' => in_array($status, [ServiceStatus::Terminated, ServiceStatus::Cancelled], true) ? Whmcs::dateTime($row->termination_date ?? null) ?? $this->run->now : null,
            'custom_fields' => Whmcs::fieldValues($this->catalog->serviceValues[(int) $row->id] ?? []),
            'created_at' => Whmcs::dateTime($row->created_at ?? null) ?? "{$registered} 00:00:00",
            'updated_at' => $this->run->now,
        ];
    }

    private function importServiceOptions(): void
    {
        if ($this->catalog->options === [] || ! $this->run->hasTables('tblhostingconfigoptions')) {
            return;
        }

        foreach ($this->run->table('tblhostingconfigoptions')->orderBy('id')->cursor() as $row) {
            $service = $this->services[(int) $row->relid] ?? null;
            $option = $service ? ($this->catalog->options["{$service['product']}:{$row->configid}"] ?? null) : null;

            if ($option === null) {
                continue;
            }

            $quantity = (int) ($row->qty ?? 0);
            [$choice, $quantity] = match ($option['type']) {
                ProductOption::DROPDOWN => [$option['choices'][(int) $row->optionid] ?? null, 1],
                default => [$quantity > 0 ? (array_values($option['choices'])[0] ?? null) : null, $option['type'] === ProductOption::CHECKBOX ? 1 : $quantity],
            };

            if ($choice === null) {
                continue;
            }

            $unitPrice = $service['currency'] === $this->run->billingCurrency ? ($choice['prices'][$service['cycle']->value] ?? 0) : 0;

            $this->run->queue('service_options', [
                'service_id' => (int) $row->relid,
                'product_option_id' => $option['id'],
                'product_option_choice_id' => $choice['id'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'created_at' => $this->run->now,
                'updated_at' => $this->run->now,
            ]);
        }
    }

    private function importInvoices(): void
    {
        $taxInclusive = $this->run->setting('TaxType') === 'Inclusive';

        $this->run->chunk('tblinvoices', function ($rows) use ($taxInclusive) {
            $items = $this->run->table('tblinvoiceitems')->whereIn('invoiceid', $rows->pluck('id'))->orderBy('id')->get()->groupBy('invoiceid');

            foreach ($rows as $row) {
                $this->importInvoice($row, $items->get($row->id, collect()), $taxInclusive);
            }
        });
    }

    /** @param  Collection<int, object>  $lines */
    private function importInvoice(object $row, Collection $lines, bool $taxInclusive): void
    {
        $this->run->report->found(self::INVOICES);
        $this->run->report->found(self::LINES, $lines->count());

        if (! isset($this->clients[(int) $row->userid])) {
            $this->run->report->skipped(self::INVOICES, "Invoice {$row->id} belongs to client {$row->userid}, which wasn't imported.");

            return;
        }

        $currency = $this->clients[(int) $row->userid];
        $credit = Whmcs::cents($row->credit ?? 0);
        $number = trim((string) ($row->invoicenum ?? ''));
        $notes = Whmcs::text($row->notes ?? '');

        // Custom WHMCS invoice numbers are kept in the notes: numbers here are the invoice id.
        if ($number !== '' && $number !== (string) $row->id) {
            $notes = trim("WHMCS invoice number: {$number}\n\n{$notes}");
        }

        DB::table('invoices')->insert([
            'id' => (int) $row->id,
            'number' => (string) $row->id,
            'client_id' => (int) $row->userid,
            'status' => Whmcs::invoiceStatus($row->status)->value,
            'currency' => $currency,
            'issue_date' => Whmcs::day(Whmcs::date($row->date) ?? $this->run->today()),
            'due_date' => Whmcs::day(Whmcs::date($row->duedate) ?? Whmcs::date($row->date) ?? $this->run->today()),
            'subtotal' => Whmcs::cents($row->subtotal),
            'tax_name' => null,
            'tax_rate' => max(0, Whmcs::cents($row->taxrate ?? 0)),
            'tax_inclusive' => $taxInclusive,
            'tax' => Whmcs::cents($row->tax ?? 0) + Whmcs::cents($row->tax2 ?? 0),
            // WHMCS takes applied credit off the total; here it is a payment on the invoice.
            'total' => Whmcs::cents($row->total) + $credit,
            'paid_at' => Whmcs::invoiceStatus($row->status) === InvoiceStatus::Paid ? Whmcs::dateTime($row->datepaid) : null,
            'notes' => $notes ?: null,
            'created_at' => Whmcs::dateTime($row->created_at ?? null) ?? Whmcs::dateTime($row->date) ?? $this->run->now,
            'updated_at' => $this->run->now,
        ]);

        if ((float) ($row->taxrate2 ?? 0) > 0) {
            $this->run->report->note(self::INVOICES, "Invoice {$row->id} had a second tax rate. Its tax total is kept, but only the first rate is shown.");
        }

        foreach ($lines as $line) {
            $this->run->queue('invoice_items', $this->line($line));
            $this->run->report->imported(self::LINES);
        }

        if ($credit > 0) {
            $this->run->queue('transactions', [
                'client_id' => (int) $row->userid,
                'invoice_id' => (int) $row->id,
                'gateway' => CreditApplier::GATEWAY,
                'currency' => $currency,
                'amount' => $credit,
                'fee' => 0,
                'created_at' => Whmcs::dateTime($row->datepaid) ?? Whmcs::dateTime($row->date) ?? $this->run->now,
                'updated_at' => $this->run->now,
            ]);
        }

        $this->invoices[(int) $row->id] = $currency;
        $this->run->report->imported(self::INVOICES);
    }

    /** @return array<string, mixed> */
    private function line(object $line): array
    {
        $type = Whmcs::itemType($line->type);
        $service = in_array(strtolower((string) $line->type), ['hosting', 'setup', 'promohosting'], true) && isset($this->services[(int) $line->relid])
            ? (int) $line->relid
            : null;
        $start = null;
        $end = null;

        if ($type === InvoiceItem::TYPE_SERVICE && $service === null) {
            $type = InvoiceItem::TYPE_MANUAL;
        }

        // The renewal period, so the billing run sees it is already invoiced.
        if ($type === InvoiceItem::TYPE_SERVICE && ($start = Whmcs::date($line->duedate)) !== null) {
            $cycle = $this->services[$service]['cycle'];
            $end = $cycle->isRecurring() ? $cycle->advance(now()->parse($start))->subDay()->toDateString() : null;
        }

        $description = Whmcs::text($line->description);

        if (mb_strlen($description) > 255) {
            $this->run->report->note(self::LINES, "A line on invoice {$line->invoiceid} was cut to 255 characters.");
        }

        return [
            'invoice_id' => (int) $line->invoiceid,
            'service_id' => $service,
            'type' => $type,
            'description' => Str::limit($description, 255, '') ?: '-',
            'amount' => Whmcs::cents($line->amount),
            'taxable' => Whmcs::on($line->taxed ?? ''),
            'period_start' => Whmcs::day($start),
            'period_end' => Whmcs::day($end),
            'created_at' => $this->run->now,
            'updated_at' => $this->run->now,
        ];
    }

    private function importTransactions(): void
    {
        $this->run->flush('transactions');
        $refunds = [];

        foreach ($this->run->table('tblaccounts')->orderBy('id')->cursor() as $row) {
            $this->run->report->found(self::TRANSACTIONS);

            if (! isset($this->clients[(int) $row->userid])) {
                $this->run->report->skipped(self::TRANSACTIONS, "Transaction {$row->id} ".((int) $row->userid === 0
                    ? "({$row->description}) isn't linked to a client."
                    : "belongs to client {$row->userid}, which wasn't imported."));

                continue;
            }

            $gateway = Whmcs::gateway($row->gateway);
            $id = DB::table('transactions')->insertGetId($this->transactionRow($row, $gateway, $this->reference($gateway, $row->transid)));
            $this->transactions[(int) $row->id] = $id;
            $refunds[$id] = (int) ($row->refundid ?? 0);
            $this->run->report->imported(self::TRANSACTIONS);
        }

        foreach (array_filter($refunds, fn (int $original) => isset($this->transactions[$original])) as $id => $original) {
            DB::table('transactions')->where('id', $id)->update(['refund_of_id' => $this->transactions[$original]]);
        }
    }

    /** Each gateway reference is unique here; WHMCS sometimes repeats one (mass payments). */
    private function reference(string $gateway, mixed $transid): ?string
    {
        $reference = Str::limit(trim((string) $transid), 240, '');

        if ($reference === '') {
            return null;
        }

        $seen = $this->references["{$gateway}|{$reference}"] = ($this->references["{$gateway}|{$reference}"] ?? 0) + 1;

        return $seen > 1 ? "{$reference} ({$seen})" : $reference;
    }

    /** @return array<string, mixed> */
    private function transactionRow(object $row, string $gateway, ?string $reference): array
    {
        return [
            'client_id' => (int) $row->userid,
            'invoice_id' => isset($this->invoices[(int) $row->invoiceid]) ? (int) $row->invoiceid : null,
            'gateway' => $gateway,
            'payment_method' => $gateway === 'paypal' ? 'paypal' : null,
            'gateway_reference' => $reference,
            'currency' => (int) ($row->currency ?? 0) > 0 ? $this->run->currency($row->currency) : $this->clients[(int) $row->userid],
            'amount' => Whmcs::cents($row->amountin) - Whmcs::cents($row->amountout),
            'fee' => Whmcs::cents($row->fees ?? 0),
            'created_at' => Whmcs::dateTime($row->date) ?? $this->run->now,
            'updated_at' => $this->run->now,
        ];
    }

    /** Remembered for whmcs:compare, which only looks at invoices made after the import. */
    private function remember(): void
    {
        Setting::updateOrCreate(['key' => self::SETTING], ['value' => json_encode([
            'imported_at' => $this->run->now,
            'last_invoice_id' => (int) $this->run->table('tblinvoices')->max('id'),
            // Clients above this were added after the import, here or in WHMCS, so their ids no longer match.
            'last_client_id' => (int) $this->run->table('tblclients')->max('id'),
        ])]);
    }
}
