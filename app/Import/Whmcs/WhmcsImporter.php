<?php

namespace App\Import\Whmcs;

use App\Auth\LegacyPassword;
use App\Billing\CreditApplier;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\CustomField;
use App\Models\InvoiceItem;
use App\Models\ProductOption;
use App\Models\Setting;
use App\Support\Money;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Copies a WHMCS database into this app: clients and their contacts, products
 * with prices and configurable options, custom fields, services, invoices,
 * transactions, ticket departments, tickets and replies.
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

    private const BATCH = 500;

    private const CLIENTS = 'Clients';

    private const CONTACTS = 'Contacts';

    private const PRODUCTS = 'Products';

    private const OPTIONS = 'Configurable options';

    private const FIELDS = 'Custom fields';

    private const SERVICES = 'Services';

    private const INVOICES = 'Invoices';

    private const LINES = 'Invoice lines';

    private const TRANSACTIONS = 'Transactions';

    private const DEPARTMENTS = 'Ticket departments';

    private const TICKETS = 'Tickets';

    private const REPLIES = 'Ticket replies';

    private const ATTACHMENTS = 'Ticket attachments';

    private const ADDONS = 'Service add-ons';

    private const DOMAINS = 'Domains';

    private ConnectionInterface $source;

    private ImportReport $report;

    private string $now;

    private string $billingCurrency;

    /** @var array<int, string> WHMCS currency id => code */
    private array $currencies = [];

    private string $defaultCurrency;

    /** @var array<int, string> client id => currency code */
    private array $clients = [];

    /** @var array<int, array{id: int, onetime: bool}> WHMCS product id => local product */
    private array $products = [];

    /** @var array<string, array{id: int, type: string, choices: array<int, array{id: int, prices: array<string, int>}>}> "product:option" => local option */
    private array $options = [];

    /** @var array<int, array{id: int, type: string, entity: string}> WHMCS field id => local field */
    private array $fields = [];

    /** @var array<int, array<int, string>> client id => [local field id => value] */
    private array $clientValues = [];

    /** @var array<int, array<int, string>> service id => [local field id => value] */
    private array $serviceValues = [];

    /** @var array<int, array{cycle: BillingCycle, currency: string, product: int}> service id => what later rows need */
    private array $services = [];

    /** @var array<int, string> invoice id => currency code */
    private array $invoices = [];

    /** @var array<int, int> WHMCS department id => local department id */
    private array $departments = [];

    /** @var array<int, int> WHMCS tblaccounts id => local transaction id */
    private array $transactions = [];

    /** @var array<string, list<array<string, mixed>>> rows waiting for a batch insert */
    private array $pending = [];

    /** @var list<string> attachment files copied in, removed again if the import fails */
    private array $copied = [];

    public function __construct(private ?string $attachmentsPath = null) {}

    /** @throws RuntimeException when this install already has data */
    public function run(ConnectionInterface $source, bool $dryRun): ImportReport
    {
        $this->source = $source;
        $this->report = new ImportReport($dryRun);
        $this->now = now()->toDateTimeString();
        $this->billingCurrency = (string) config('billing.currency');

        foreach (self::TARGET_TABLES as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException("This install already has {$table}. Import into a new, empty install (run php artisan migrate:fresh first if it only has test data).");
            }
        }

        DB::beginTransaction();

        try {
            $this->import();
            $this->compareTotals();

            if (! $dryRun) {
                $this->remember();
            }
        } catch (Throwable $e) {
            DB::rollBack();
            $this->removeCopiedFiles();

            throw $e;
        }

        $dryRun ? DB::rollBack() : DB::commit();

        return $this->report;
    }

    private function import(): void
    {
        $this->loadCurrencies();
        $this->importProducts();
        $this->importOptions();
        $this->importCustomFields();
        $this->importClients();
        $this->importContacts();
        $this->importServices();
        $this->importServiceOptions();
        $this->importInvoices();
        $this->importTransactions();
        $this->importDepartments();
        $this->importTickets();
        $this->reportNotImported();
        $this->flushAll();
    }

    /**
     * Things WHMCS bills that this app doesn't have yet: listed so nothing
     * recurring is lost without anyone noticing, and so staff can bill them
     * another way (whmcs:compare lists their renewals as WHMCS-only).
     */
    private function reportNotImported(): void
    {
        $billed = ['Active', 'Suspended', 'Pending'];

        if ($this->hasTables('tblhostingaddons')) {
            foreach ($this->source->table('tblhostingaddons')->whereIn('status', $billed)->orderBy('id')->cursor() as $addon) {
                $this->report->found(self::ADDONS);
                $this->report->skipped(self::ADDONS, "Add-on {$addon->id} (".Whmcs::text($addon->name ?? '').") on service {$addon->hostingid}, {$addon->recurring} {$addon->billingcycle}: add-ons aren't supported yet, so add it to the service's price or bill it by hand.");
            }
        }

        if ($this->hasTables('tbldomains')) {
            foreach ($this->source->table('tbldomains')->whereIn('status', $billed)->orderBy('id')->cursor() as $domain) {
                $this->report->found(self::DOMAINS);
                $this->report->skipped(self::DOMAINS, "Domain {$domain->domain} of client {$domain->userid}, {$domain->recurringamount} due {$domain->nextduedate}: domains aren't supported yet.");
            }
        }

        if (DB::table('tax_rules')->doesntExist() && $this->source->table('tblinvoices')->where('taxrate', '>', 0)->exists()) {
            $this->report->note(self::INVOICES, 'WHMCS charged tax on some invoices, but there are no tax rules here yet. Add them under Tax before the first billing run, or new invoices will have no tax.');
        }
    }

    private function loadCurrencies(): void
    {
        foreach ($this->source->table('tblcurrencies')->orderBy('id')->get() as $currency) {
            $this->currencies[(int) $currency->id] = strtoupper((string) $currency->code);

            if (Whmcs::on($currency->default ?? '')) {
                $this->defaultCurrency = strtoupper((string) $currency->code);
            }
        }

        $this->defaultCurrency ??= $this->currencies[array_key_first($this->currencies)] ?? $this->billingCurrency;
    }

    private function currency(mixed $id): string
    {
        return $this->currencies[(int) $id] ?? $this->defaultCurrency;
    }

    private function importProducts(): void
    {
        $prices = $this->source->table('tblpricing')->where('type', 'product')->get()->groupBy('relid');

        foreach ($this->source->table('tblproducts')->orderBy('id')->cursor() as $row) {
            $this->report->found(self::PRODUCTS);
            $onetime = strtolower((string) $row->paytype) === 'onetime';

            $id = DB::table('products')->insertGetId([
                'name' => Whmcs::text($row->name),
                'description' => $this->plain($row->description ?? '') ?: null,
                'active' => ! Whmcs::on($row->hidden ?? '') && ! Whmcs::on($row->retired ?? ''),
                'taxable' => Whmcs::on($row->tax ?? ''),
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);

            $this->products[(int) $row->id] = ['id' => $id, 'onetime' => $onetime];
            $this->report->imported(self::PRODUCTS);

            if (filled($row->servertype ?? null)) {
                $this->report->note(self::PRODUCTS, "\"{$row->name}\" used the WHMCS {$row->servertype} module. Pick a provisioning module for it on its product page.");
            }

            if (strtolower((string) $row->paytype) === 'free') {
                continue;
            }

            foreach ($prices->get($row->id, collect()) as $price) {
                foreach ($this->pricesFrom($price, $onetime) as $cycle => [$amount, $setup]) {
                    DB::table('product_prices')->insert([
                        'product_id' => $id,
                        'billing_cycle' => $cycle,
                        'currency' => $this->currency($price->currency),
                        'price' => $amount,
                        'setup_fee' => $setup,
                        'created_at' => $this->now,
                        'updated_at' => $this->now,
                    ]);
                }
            }
        }
    }

    /**
     * Enabled cycles of a tblpricing row as [cycle => [price, setup fee]].
     * WHMCS marks a cycle it doesn't offer with -1, and keeps one-time prices
     * in the monthly columns.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    private function pricesFrom(object $row, bool $onetime): array
    {
        if ($onetime) {
            return Whmcs::cents($row->monthly) < 0 ? [] : [BillingCycle::OneTime->value => [Whmcs::cents($row->monthly), max(0, Whmcs::cents($row->msetupfee))]];
        }

        $prices = [];

        foreach (Whmcs::PRICE_COLUMNS as $cycle => [$priceColumn, $setupColumn]) {
            $amount = Whmcs::cents($row->{$priceColumn} ?? -1);

            if ($amount >= 0) {
                $prices[$cycle] = [$amount, max(0, Whmcs::cents($row->{$setupColumn} ?? 0))];
            }
        }

        return $prices;
    }

    /**
     * WHMCS shares configurable option groups between products; here each
     * product gets its own copy of the options linked to it. Prices come over
     * in the billing currency only, the one option prices are kept in.
     */
    private function importOptions(): void
    {
        if (! $this->hasTables('tblproductconfiglinks', 'tblproductconfigoptions', 'tblproductconfigoptionssub')) {
            return;
        }

        $currencyId = array_search($this->billingCurrency, $this->currencies, true);
        $prices = $this->source->table('tblpricing')->where('type', 'configoptions')
            ->where('currency', $currencyId === false ? -1 : $currencyId)->get()->keyBy('relid');
        $options = $this->source->table('tblproductconfigoptions')->orderBy('order')->orderBy('id')->get()->groupBy('gid');
        $choices = $this->source->table('tblproductconfigoptionssub')->orderBy('sortorder')->orderBy('id')->get()->groupBy('configid');

        if ($currencyId === false && $prices->isEmpty()) {
            $this->report->note(self::OPTIONS, "WHMCS has no {$this->billingCurrency} currency, so option prices were left blank.");
        }

        foreach ($this->source->table('tblproductconfiglinks')->orderBy('id')->cursor() as $link) {
            $product = $this->products[(int) $link->pid] ?? null;

            if ($product === null) {
                continue;
            }

            foreach ($options->get($link->gid, collect()) as $option) {
                $this->report->found(self::OPTIONS);
                $type = match ((int) $option->optiontype) {
                    3 => ProductOption::CHECKBOX,
                    4 => ProductOption::QUANTITY,
                    default => ProductOption::DROPDOWN,
                };

                $optionId = DB::table('product_options')->insertGetId([
                    'product_id' => $product['id'],
                    'name' => Whmcs::label($option->optionname),
                    'type' => $type,
                    'min_quantity' => max(0, (int) ($option->qtyminimum ?? 0)),
                    'max_quantity' => ((int) ($option->qtymaximum ?? 0)) ?: null,
                    'sort_order' => (int) ($option->order ?? 0),
                    'created_at' => $this->now,
                    'updated_at' => $this->now,
                ]);

                $subs = $choices->get($option->id, collect());

                if ($type !== ProductOption::DROPDOWN && $subs->count() > 1) {
                    $this->report->note(self::OPTIONS, '"'.Whmcs::label($option->optionname).'" had '.$subs->count().' choices; only the first was kept, since a '.$type.' has one.');
                    $subs = $subs->take(1);
                }

                $local = ['id' => $optionId, 'type' => $type, 'choices' => []];

                foreach ($subs as $sub) {
                    $price = $prices->get($sub->id);
                    $cyclePrices = $price ? $this->optionPrices($price, $product['onetime']) : [];

                    if ($price && $this->setupFeesDiffer($price)) {
                        $this->report->note(self::OPTIONS, '"'.Whmcs::label($option->optionname).': '.Whmcs::label($sub->optionname).'" has a different setup fee per billing cycle in WHMCS. Options have one setup fee here, so the monthly one was kept.');
                    }

                    $local['choices'][(int) $sub->id] = [
                        'id' => DB::table('product_option_choices')->insertGetId([
                            'product_option_id' => $optionId,
                            'name' => Whmcs::label($sub->optionname),
                            'prices' => $cyclePrices === [] ? null : json_encode($cyclePrices),
                            'setup_fee' => $price ? max(0, Whmcs::cents($price->msetupfee)) : 0,
                            'sort_order' => (int) ($sub->sortorder ?? 0),
                            'created_at' => $this->now,
                            'updated_at' => $this->now,
                        ]),
                        'prices' => $cyclePrices,
                    ];
                }

                $this->options["{$link->pid}:{$option->id}"] = $local;
                $this->report->imported(self::OPTIONS);
            }
        }
    }

    private function setupFeesDiffer(object $row): bool
    {
        $fees = array_map(fn (array $columns) => Whmcs::cents($row->{$columns[1]} ?? 0), Whmcs::PRICE_COLUMNS);

        return count(array_unique(array_filter($fees, fn (int $fee) => $fee > 0))) > 1
            || (max($fees) > 0 && Whmcs::cents($row->msetupfee) <= 0);
    }

    /** @return array<string, int> */
    private function optionPrices(object $row, bool $onetime): array
    {
        if ($onetime) {
            $amount = Whmcs::cents($row->monthly);

            return $amount > 0 ? [BillingCycle::OneTime->value => $amount] : [];
        }

        $prices = [];

        foreach (Whmcs::PRICE_COLUMNS as $cycle => [$column]) {
            $amount = Whmcs::cents($row->{$column} ?? 0);

            if ($amount > 0) {
                $prices[$cycle] = $amount;
            }
        }

        return $prices;
    }

    private function importCustomFields(): void
    {
        if (! $this->hasTables('tblcustomfields')) {
            return;
        }

        foreach ($this->source->table('tblcustomfields')->whereIn('type', ['client', 'product'])->orderBy('sortorder')->orderBy('id')->cursor() as $row) {
            $this->report->found(self::FIELDS);
            $entity = $row->type === 'client' ? CustomField::CLIENT : CustomField::SERVICE;
            $productId = null;

            if ($entity === CustomField::SERVICE) {
                $productId = $this->products[(int) $row->relid]['id'] ?? null;

                if ($productId === null) {
                    $this->report->skipped(self::FIELDS, "\"{$row->fieldname}\" belongs to a product that wasn't found.");

                    continue;
                }
            }

            $type = match (strtolower((string) $row->fieldtype)) {
                'textarea' => 'textarea',
                'dropdown' => 'dropdown',
                'tickbox' => CustomField::CHECKBOX,
                default => 'text',
            };

            $id = DB::table('custom_fields')->insertGetId([
                'entity' => $entity,
                'product_id' => $productId,
                'name' => Str::limit(Whmcs::label($row->fieldname), 250, ''),
                'type' => $type,
                'options' => $type === 'dropdown'
                    ? implode("\n", array_filter(array_map(Whmcs::text(...), explode(',', (string) $row->fieldoptions))))
                    : null,
                'help' => Str::limit(Whmcs::text($row->description ?? ''), 250, '') ?: null,
                'required' => Whmcs::on($row->required ?? ''),
                'admin_only' => Whmcs::on($row->adminonly ?? ''),
                'sort_order' => (int) ($row->sortorder ?? 0),
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);

            $this->fields[(int) $row->id] = ['id' => $id, 'type' => $type, 'entity' => $entity];
            $this->report->imported(self::FIELDS);
        }

        if ($this->fields === [] || ! $this->hasTables('tblcustomfieldsvalues')) {
            return;
        }

        foreach ($this->source->table('tblcustomfieldsvalues')->whereIn('fieldid', array_keys($this->fields))->cursor() as $value) {
            $field = $this->fields[(int) $value->fieldid];
            $text = $field['type'] === CustomField::CHECKBOX
                ? (Whmcs::on($value->value) ? '1' : '')
                : Whmcs::text($value->value);

            if ($text === '') {
                continue;
            }

            if ($field['entity'] === CustomField::CLIENT) {
                $this->clientValues[(int) $value->relid][$field['id']] = $text;
            } else {
                $this->serviceValues[(int) $value->relid][$field['id']] = $text;
            }
        }
    }

    private function importClients(): void
    {
        $passwords = $this->ownerPasswords();
        $emails = [];

        foreach ($this->source->table('tblclients')->orderBy('id')->cursor() as $row) {
            $this->report->found(self::CLIENTS);
            $email = strtolower(trim((string) $row->email));

            if ($email === '') {
                $this->report->skipped(self::CLIENTS, "Client {$row->id} ({$this->name($row)}) has no email address.");

                continue;
            }

            if (isset($emails[$email])) {
                $this->report->skipped(self::CLIENTS, "Client {$row->id} has the same email as client {$emails[$email]} ({$email}). Merge them in WHMCS or change one address, then import again.");

                continue;
            }

            $emails[$email] = (int) $row->id;
            $currency = $this->currency($row->currency ?? 0);
            $credit = Whmcs::cents($row->credit ?? 0);
            $country = strtoupper(trim((string) ($row->country ?? '')));
            $values = $this->clientValues[(int) $row->id] ?? [];
            $created = Whmcs::dateTime($row->created_at ?? null) ?? Whmcs::dateTime($row->datecreated ?? null) ?? $this->now;

            DB::table('clients')->insert([
                'id' => (int) $row->id,
                'first_name' => Str::limit(Whmcs::text($row->firstname), 250, '') ?: '-',
                'last_name' => Str::limit(Whmcs::text($row->lastname), 250, ''),
                'company' => Str::limit(Whmcs::text($row->companyname ?? ''), 250, '') ?: null,
                'email' => $email,
                'password' => null,
                'legacy_password' => $this->passwordHash($row, $passwords[(int) $row->id] ?? null),
                'phone' => Str::limit(Whmcs::text($row->phonenumber ?? ''), 250, '') ?: null,
                'address1' => Str::limit(Whmcs::text($row->address1 ?? ''), 250, '') ?: null,
                'address2' => Str::limit(Whmcs::text($row->address2 ?? ''), 250, '') ?: null,
                'city' => Str::limit(Whmcs::text($row->city ?? ''), 250, '') ?: null,
                'state' => Str::limit(Whmcs::text($row->state ?? ''), 250, '') ?: null,
                'postcode' => Str::limit(Whmcs::text($row->postcode ?? ''), 250, '') ?: null,
                'country' => strlen($country) === 2 ? $country : null,
                'currency' => $currency,
                'tax_exempt' => Whmcs::on($row->taxexempt ?? ''),
                'status' => Whmcs::clientStatus($row->status ?? '')->value,
                'credit_balance' => $credit,
                'notes' => Whmcs::text($row->notes ?? '') ?: null,
                'custom_fields' => $values === [] ? null : json_encode(array_combine(array_map('strval', array_keys($values)), $values)),
                'created_at' => $created,
                'updated_at' => $this->now,
            ]);

            if ($credit !== 0) {
                DB::table('credit_entries')->insert([
                    'client_id' => (int) $row->id,
                    'amount' => $credit,
                    'balance_after' => $credit,
                    'description' => 'Credit balance brought over from WHMCS',
                    'created_at' => $this->now,
                ]);
            }

            $this->clients[(int) $row->id] = $currency;
            $this->report->imported(self::CLIENTS);
        }
    }

    /** The client's WHMCS hash when it can be checked at login; older formats mean a password reset. */
    private function passwordHash(object $row, ?string $ownerHash): ?string
    {
        $hash = $ownerHash ?? (filled($row->password ?? null) ? (string) $row->password : null);

        if ($hash !== null && ! LegacyPassword::supported($hash)) {
            $this->report->note(self::CLIENTS, "Client {$row->id} has an old WHMCS password hash that can't be checked safely, so they'll need to use Forgot password.");

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
        if (! $this->hasTables('tblusers', 'tblusers_clients')) {
            return [];
        }

        return $this->source->table('tblusers_clients')
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
        if (! $this->hasTables('tblcontacts')) {
            return;
        }

        foreach ($this->source->table('tblcontacts')->orderBy('id')->cursor() as $row) {
            $this->report->found(self::CONTACTS);
            $email = strtolower(trim((string) $row->email));

            if (! isset($this->clients[(int) $row->userid])) {
                $this->report->skipped(self::CONTACTS, "Contact {$row->id} ({$this->name($row)}) belongs to client {$row->userid}, which wasn't imported.");

                continue;
            }

            if ($email === '') {
                $this->report->skipped(self::CONTACTS, "Contact {$row->id} ({$this->name($row)}) of client {$row->userid} has no email address.");

                continue;
            }

            if (Whmcs::on($row->subaccount ?? '')) {
                $this->report->note(self::CONTACTS, "Contact {$email} of client {$row->userid} could log in to WHMCS. Contacts can't log in here yet, so they won't be able to.");
            }

            $this->queue('client_contacts', [
                'client_id' => (int) $row->userid,
                'first_name' => Str::limit(Whmcs::text($row->firstname), 250, '') ?: '-',
                'last_name' => Str::limit(Whmcs::text($row->lastname), 250, ''),
                'email' => $email,
                'phone' => Str::limit(Whmcs::text($row->phonenumber ?? ''), 50, '') ?: null,
                'invoice_emails' => Whmcs::on($row->invoiceemails ?? ''),
                'support_emails' => Whmcs::on($row->supportemails ?? ''),
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);
            $this->report->imported(self::CONTACTS);
        }
    }

    private function importServices(): void
    {
        foreach ($this->source->table('tblhosting')->orderBy('id')->cursor() as $row) {
            $this->report->found(self::SERVICES);
            $product = $this->products[(int) $row->packageid] ?? null;
            $cycle = Whmcs::cycle($row->billingcycle);

            $problem = match (true) {
                ! isset($this->clients[(int) $row->userid]) => "belongs to client {$row->userid}, which wasn't imported",
                $product === null => "uses product {$row->packageid}, which wasn't found",
                $cycle === null => "has a billing cycle this app doesn't have ({$row->billingcycle})",
                default => null,
            };

            if ($problem !== null) {
                $this->report->skipped(self::SERVICES, "Service {$row->id} ({$row->domain}) {$problem}.");

                continue;
            }

            $status = Whmcs::serviceStatus($row->domainstatus);
            $free = $cycle === BillingCycle::OneTime && strtolower(str_replace(' ', '', (string) $row->billingcycle)) === 'freeaccount';
            $values = $this->serviceValues[(int) $row->id] ?? [];
            $registered = Whmcs::date($row->regdate) ?? substr($this->now, 0, 10);

            DB::table('services')->insert([
                'id' => (int) $row->id,
                'client_id' => (int) $row->userid,
                'product_id' => $product['id'],
                'label' => Str::limit(Whmcs::text($row->domain ?? ''), 250, '') ?: null,
                'billing_cycle' => $cycle->value,
                'recurring_amount' => $free ? 0 : Whmcs::cents($row->amount),
                'status' => $status->value,
                'registration_date' => $this->day($registered),
                'next_due_date' => $cycle->isRecurring() ? $this->day(Whmcs::date($row->nextduedate)) : null,
                'suspended_at' => $status === ServiceStatus::Suspended ? $this->now : null,
                'suspension_reason' => $status === ServiceStatus::Suspended ? (Str::limit(Whmcs::text($row->suspendreason ?? ''), 250, '') ?: 'Suspended in WHMCS') : null,
                'terminated_at' => $status === ServiceStatus::Terminated ? Whmcs::dateTime($row->termination_date ?? null) ?? $this->now : null,
                'custom_fields' => $values === [] ? null : json_encode(array_combine(array_map('strval', array_keys($values)), $values)),
                'created_at' => Whmcs::dateTime($row->created_at ?? null) ?? "{$registered} 00:00:00",
                'updated_at' => $this->now,
            ]);

            $this->services[(int) $row->id] = ['cycle' => $cycle, 'currency' => $this->clients[(int) $row->userid], 'product' => (int) $row->packageid];
            $this->report->imported(self::SERVICES);
        }
    }

    private function importServiceOptions(): void
    {
        if ($this->options === [] || ! $this->hasTables('tblhostingconfigoptions')) {
            return;
        }

        foreach ($this->source->table('tblhostingconfigoptions')->orderBy('id')->cursor() as $row) {
            $service = $this->services[(int) $row->relid] ?? null;
            $option = $service ? ($this->options["{$service['product']}:{$row->configid}"] ?? null) : null;

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

            $unitPrice = $service['currency'] === $this->billingCurrency ? ($choice['prices'][$service['cycle']->value] ?? 0) : 0;

            $this->queue('service_options', [
                'service_id' => (int) $row->relid,
                'product_option_id' => $option['id'],
                'product_option_choice_id' => $choice['id'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);
        }
    }

    private function importInvoices(): void
    {
        $taxInclusive = $this->setting('TaxType') === 'Inclusive';

        // In batches, so a large WHMCS install's invoice lines never all sit in memory.
        $this->source->table('tblinvoices')->chunkById(self::BATCH, function ($rows) use ($taxInclusive) {
            $items = $this->source->table('tblinvoiceitems')->whereIn('invoiceid', $rows->pluck('id'))->orderBy('id')->get()->groupBy('invoiceid');

            foreach ($rows as $row) {
                $this->importInvoice($row, $items->get($row->id, collect()), $taxInclusive);
            }
        });
    }

    /** @param  Collection<int, object>  $lines */
    private function importInvoice(object $row, Collection $lines, bool $taxInclusive): void
    {
        $this->report->found(self::INVOICES);
        $this->report->found(self::LINES, $lines->count());

        if (! isset($this->clients[(int) $row->userid])) {
            $this->report->skipped(self::INVOICES, "Invoice {$row->id} belongs to client {$row->userid}, which wasn't imported.");

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
            'issue_date' => $this->day(Whmcs::date($row->date) ?? substr($this->now, 0, 10)),
            'due_date' => $this->day(Whmcs::date($row->duedate) ?? Whmcs::date($row->date) ?? substr($this->now, 0, 10)),
            'subtotal' => Whmcs::cents($row->subtotal),
            'tax_name' => null,
            'tax_rate' => max(0, Whmcs::cents($row->taxrate ?? 0)),
            'tax_inclusive' => $taxInclusive,
            'tax' => Whmcs::cents($row->tax ?? 0) + Whmcs::cents($row->tax2 ?? 0),
            // WHMCS takes applied credit off the total; here it is a payment on the invoice.
            'total' => Whmcs::cents($row->total) + $credit,
            'paid_at' => Whmcs::invoiceStatus($row->status) === InvoiceStatus::Paid ? Whmcs::dateTime($row->datepaid) : null,
            'notes' => $notes ?: null,
            'created_at' => Whmcs::dateTime($row->created_at ?? null) ?? Whmcs::dateTime($row->date) ?? $this->now,
            'updated_at' => $this->now,
        ]);

        if ((float) ($row->taxrate2 ?? 0) > 0) {
            $this->report->note(self::INVOICES, "Invoice {$row->id} had a second tax rate. Its tax total is kept, but only the first rate is shown.");
        }

        foreach ($lines as $line) {
            $this->queue('invoice_items', $this->line($line));
            $this->report->imported(self::LINES);
        }

        if ($credit > 0) {
            $this->queue('transactions', [
                'client_id' => (int) $row->userid,
                'invoice_id' => (int) $row->id,
                'gateway' => CreditApplier::GATEWAY,
                'currency' => $currency,
                'amount' => $credit,
                'fee' => 0,
                'created_at' => Whmcs::dateTime($row->datepaid) ?? Whmcs::dateTime($row->date) ?? $this->now,
                'updated_at' => $this->now,
            ]);
        }

        $this->invoices[(int) $row->id] = $currency;
        $this->report->imported(self::INVOICES);
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
            $this->report->note(self::LINES, "A line on invoice {$line->invoiceid} was cut to 255 characters.");
        }

        return [
            'invoice_id' => (int) $line->invoiceid,
            'service_id' => $service,
            'type' => $type,
            'description' => Str::limit($description, 255, '') ?: '-',
            'amount' => Whmcs::cents($line->amount),
            'taxable' => Whmcs::on($line->taxed ?? ''),
            'period_start' => $this->day($start),
            'period_end' => $this->day($end),
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ];
    }

    private function importTransactions(): void
    {
        $this->flush('transactions');
        $references = [];
        $refunds = [];

        foreach ($this->source->table('tblaccounts')->orderBy('id')->cursor() as $row) {
            $this->report->found(self::TRANSACTIONS);

            if (! isset($this->clients[(int) $row->userid])) {
                $this->report->skipped(self::TRANSACTIONS, (int) $row->userid === 0
                    ? "Transaction {$row->id} ({$row->description}) isn't linked to a client."
                    : "Transaction {$row->id} belongs to client {$row->userid}, which wasn't imported.");

                continue;
            }

            $invoiceId = isset($this->invoices[(int) $row->invoiceid]) ? (int) $row->invoiceid : null;
            $gateway = Whmcs::gateway($row->gateway);
            $reference = Str::limit(trim((string) $row->transid), 240, '') ?: null;

            // Each gateway reference is unique here; WHMCS sometimes repeats one (mass payments).
            if ($reference !== null) {
                $key = "{$gateway}|{$reference}";
                $references[$key] = ($references[$key] ?? 0) + 1;

                if ($references[$key] > 1) {
                    $reference .= " ({$references[$key]})";
                }
            }

            $amount = Whmcs::cents($row->amountin) - Whmcs::cents($row->amountout);

            $id = DB::table('transactions')->insertGetId([
                'client_id' => (int) $row->userid,
                'invoice_id' => $invoiceId,
                'gateway' => $gateway,
                'payment_method' => $gateway === 'paypal' ? 'paypal' : null,
                'gateway_reference' => $reference,
                'currency' => (int) ($row->currency ?? 0) > 0 ? $this->currency($row->currency) : $this->clients[(int) $row->userid],
                'amount' => $amount,
                'fee' => Whmcs::cents($row->fees ?? 0),
                'created_at' => Whmcs::dateTime($row->date) ?? $this->now,
                'updated_at' => $this->now,
            ]);

            $this->transactions[(int) $row->id] = $id;

            if ((int) ($row->refundid ?? 0) > 0) {
                $refunds[$id] = (int) $row->refundid;
            }

            $this->report->imported(self::TRANSACTIONS);
        }

        foreach ($refunds as $id => $original) {
            if (isset($this->transactions[$original])) {
                DB::table('transactions')->where('id', $id)->update(['refund_of_id' => $this->transactions[$original]]);
            }
        }
    }

    private function importDepartments(): void
    {
        $existing = DB::table('departments')->whereNotNull('email')->pluck('id', 'email')
            ->mapWithKeys(fn ($id, $email) => [strtolower($email) => $id])->all();

        foreach ($this->source->table('tblticketdepartments')->orderBy('id')->cursor() as $row) {
            $this->report->found(self::DEPARTMENTS);
            $email = strtolower(trim((string) ($row->email ?? ''))) ?: null;

            if ($email !== null && isset($existing[$email])) {
                $this->departments[(int) $row->id] = $existing[$email];
                $this->report->note(self::DEPARTMENTS, "\"{$row->name}\" uses {$email}, like a department already here, so its tickets went to that one.");

                continue;
            }

            $id = DB::table('departments')->insertGetId([
                'name' => Str::limit(Whmcs::text($row->name), 250, ''),
                'email' => $email,
                'active' => ! Whmcs::on($row->hidden ?? ''),
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);

            if ($email !== null) {
                $existing[$email] = $id;
            }

            $this->departments[(int) $row->id] = $id;
            $this->report->imported(self::DEPARTMENTS);
        }
    }

    private function importTickets(): void
    {
        $this->source->table('tbltickets')->chunkById(self::BATCH, function ($rows) {
            $replies = $this->source->table('tblticketreplies')->whereIn('tid', $rows->pluck('id'))->orderBy('id')->get()->groupBy('tid');

            foreach ($rows as $row) {
                $this->importTicket($row, $replies->get($row->id, collect()));
            }
        });
    }

    /** @param  Collection<int, object>  $messages */
    private function importTicket(object $row, Collection $messages): void
    {
        $this->report->found(self::TICKETS);
        $this->report->found(self::REPLIES, $messages->count());

        $problem = match (true) {
            (int) $row->userid === 0 => 'was sent by someone without a client account',
            ! isset($this->clients[(int) $row->userid]) => "belongs to client {$row->userid}, which wasn't imported",
            ! isset($this->departments[(int) $row->did]) => "is in department {$row->did}, which wasn't found",
            (int) ($row->merged_ticket_id ?? 0) > 0 => "was merged into ticket {$row->merged_ticket_id}",
            default => null,
        };

        if ($problem !== null) {
            $this->report->skipped(self::TICKETS, "Ticket #{$row->tid} ({$row->title}) {$problem}.");

            return;
        }

        $opened = Whmcs::dateTime($row->date) ?? $this->now;

        DB::table('tickets')->insert([
            'id' => (int) $row->id,
            'whmcs_tid' => Str::limit((string) $row->tid, 32, ''),
            'client_id' => (int) $row->userid,
            'department_id' => $this->departments[(int) $row->did],
            'subject' => Str::limit(Whmcs::text($row->title), 250, '') ?: '(No subject)',
            'status' => Whmcs::ticketStatus($row->status)->value,
            'priority' => Whmcs::priority($row->urgency)->value,
            'last_reply_at' => Whmcs::dateTime($row->lastreply ?? null) ?? $opened,
            'created_at' => $opened,
            'updated_at' => $this->now,
        ]);

        $this->message((int) $row->id, (int) $row->userid, $row, $opened);

        foreach ($messages as $reply) {
            $this->message((int) $row->id, (int) $row->userid, $reply, Whmcs::dateTime($reply->date) ?? $opened);
            $this->report->imported(self::REPLIES);
        }

        $this->report->imported(self::TICKETS);
    }

    /** A ticket's first message (kept on the ticket row in WHMCS) or one of its replies. */
    private function message(int $ticketId, int $clientId, object $row, string $at): void
    {
        $staff = trim((string) ($row->admin ?? '')) !== '';
        $files = array_values(array_filter(explode('|', (string) ($row->attachment ?? ''))));
        $body = Whmcs::text($row->message ?? '');

        $messageId = DB::table('ticket_messages')->insertGetId([
            'ticket_id' => $ticketId,
            'user_id' => null,
            'client_id' => $staff ? null : $clientId,
            'author_name' => Str::limit($staff ? Whmcs::text($row->admin) : Whmcs::text($row->name ?? ''), 250, '') ?: null,
            'body' => $body !== '' ? $body : ($files !== [] ? '(Attachments only.)' : '(No message.)'),
            'internal' => false,
            'via' => 'whmcs',
            'created_at' => $at,
        ]);

        foreach ($files as $stored) {
            $this->attach($ticketId, $messageId, $stored, $at);
        }
    }

    private function attach(int $ticketId, int $messageId, string $stored, string $at): void
    {
        $this->report->found(self::ATTACHMENTS);

        if ($this->attachmentsPath === null) {
            $this->report->skipped(self::ATTACHMENTS, "{$stored} on ticket {$ticketId}: run with --attachments to bring files over.");

            return;
        }

        $file = rtrim($this->attachmentsPath, '/').'/'.basename($stored);

        if (! is_file($file)) {
            $this->report->skipped(self::ATTACHMENTS, "{$stored} on ticket {$ticketId} is missing from {$this->attachmentsPath}.");

            return;
        }

        $path = "tickets/{$ticketId}/".Str::random(40);

        if (! $this->report->dryRun) {
            Storage::disk('local')->put($path, (string) file_get_contents($file));
            $this->copied[] = $path;
        }

        $this->queue('ticket_attachments', [
            'ticket_message_id' => $messageId,
            'path' => $path,
            'name' => Str::limit(Whmcs::attachmentName($stored), 250, ''),
            'mime' => mime_content_type($file) ?: null,
            'size' => (int) filesize($file),
            'created_at' => $at,
        ]);
        $this->report->imported(self::ATTACHMENTS);
    }

    /** Totals in WHMCS against the same totals here, by currency. */
    private function compareTotals(): void
    {
        $this->flushAll();
        $count = fn (string $table) => (string) DB::table($table)->count();

        $this->report->check('Clients', (string) $this->source->table('tblclients')->count(), $count('clients'));
        $this->report->check('Services', (string) $this->source->table('tblhosting')->count(), $count('services'));
        $this->report->check('Invoices', (string) $this->source->table('tblinvoices')->count(), $count('invoices'));
        $this->report->check('Tickets', (string) $this->source->table('tbltickets')->count(), $count('tickets'));

        $whmcsClients = $this->source->table('tblclients')->pluck('currency', 'id');
        $currencyOf = fn ($clientId) => $this->currency($whmcsClients[$clientId] ?? 0);

        $this->compareByCurrency(
            'Recurring amount of active and suspended services',
            $this->source->table('tblhosting')->whereIn('domainstatus', ['Active', 'Suspended'])->get(['userid', 'amount', 'billingcycle'])
                ->reject(fn ($row) => strtolower(str_replace(' ', '', (string) $row->billingcycle)) === 'freeaccount')
                ->groupBy(fn ($row) => $currencyOf($row->userid))
                ->map(fn ($rows) => $rows->sum(fn ($row) => Whmcs::cents($row->amount))),
            DB::table('services')->join('clients', 'clients.id', '=', 'services.client_id')
                ->whereIn('services.status', [ServiceStatus::Active->value, ServiceStatus::Suspended->value])
                ->groupBy('clients.currency')->selectRaw('clients.currency as k, sum(services.recurring_amount) as total')->pluck('total', 'k'),
        );

        $whmcsPaid = $this->source->table('tblaccounts')->where('invoiceid', '>', 0)->groupBy('invoiceid')
            ->selectRaw('invoiceid as k, sum(amountin) - sum(amountout) as total')->pluck('total', 'k');

        $this->compareByCurrency(
            'Balance due on unpaid invoices',
            $this->source->table('tblinvoices')->whereIn('status', ['Unpaid', 'Collections', 'Payment Pending'])->get(['id', 'userid', 'total'])
                ->groupBy(fn ($row) => $currencyOf($row->userid))
                ->map(fn ($rows) => $rows->sum(fn ($row) => Whmcs::cents($row->total) - Whmcs::cents($whmcsPaid[$row->id] ?? 0))),
            DB::table('invoices')->where('status', InvoiceStatus::Unpaid->value)
                ->leftJoinSub(DB::table('transactions')->whereNotNull('invoice_id')->groupBy('invoice_id')->select('invoice_id', DB::raw('sum(amount) as paid')), 'paid', 'paid.invoice_id', '=', 'invoices.id')
                ->groupBy('invoices.currency')->selectRaw('invoices.currency as k, sum(invoices.total - coalesce(paid.paid, 0)) as total')->pluck('total', 'k'),
        );

        $this->compareByCurrency(
            'Payments received, less refunds',
            $this->source->table('tblaccounts')->where('userid', '>', 0)->get(['userid', 'currency', 'amountin', 'amountout'])
                ->groupBy(fn ($row) => (int) $row->currency > 0 ? $this->currency($row->currency) : $currencyOf($row->userid))
                ->map(fn ($rows) => $rows->sum(fn ($row) => Whmcs::cents($row->amountin) - Whmcs::cents($row->amountout))),
            DB::table('transactions')->where('gateway', '!=', CreditApplier::GATEWAY)
                ->groupBy('currency')->selectRaw('currency as k, sum(amount) as total')->pluck('total', 'k'),
        );

        $this->compareByCurrency(
            'Client credit balances',
            $this->source->table('tblclients')->get(['currency', 'credit'])
                ->groupBy(fn ($row) => $this->currency($row->currency))
                ->map(fn ($rows) => $rows->sum(fn ($row) => Whmcs::cents($row->credit))),
            DB::table('clients')->groupBy('currency')->selectRaw('currency as k, sum(credit_balance) as total')->pluck('total', 'k'),
        );

        $this->compareInvoiceBalances($whmcsPaid->all());
    }

    /**
     * @param  iterable<string, int|string>  $whmcs
     * @param  iterable<string, int|string>  $here
     */
    private function compareByCurrency(string $label, iterable $whmcs, iterable $here): void
    {
        $whmcs = collect($whmcs)->map(fn ($cents) => (int) $cents);
        $here = collect($here)->map(fn ($cents) => (int) $cents);

        foreach ($whmcs->keys()->merge($here->keys())->unique()->sort() as $currency) {
            $this->report->check(
                "{$label} ({$currency})",
                Money::format($whmcs->get($currency, 0), $currency),
                Money::format($here->get($currency, 0), $currency),
            );
        }
    }

    /** @param  array<int|string, string>  $whmcsPaid  invoice id => amount paid in WHMCS */
    private function compareInvoiceBalances(array $whmcsPaid): void
    {
        $paidHere = DB::table('transactions')->whereNotNull('invoice_id')->groupBy('invoice_id')->selectRaw('invoice_id as k, sum(amount) as total')->pluck('total', 'k');

        foreach ($this->source->table('tblinvoices')->orderBy('id')->cursor() as $row) {
            if (! isset($this->invoices[(int) $row->id])) {
                continue;
            }

            $whmcs = Whmcs::cents($row->total) - Whmcs::cents($whmcsPaid[$row->id] ?? 0);
            $here = Whmcs::cents($row->total) + Whmcs::cents($row->credit ?? 0) - (int) ($paidHere[$row->id] ?? 0);

            if ($whmcs !== $here) {
                $currency = $this->invoices[(int) $row->id];
                $this->report->note(self::INVOICES, "Invoice {$row->id} has ".Money::format($here, $currency).' due here but '.Money::format($whmcs, $currency).' in WHMCS.');
            }
        }
    }

    /** Remembered for whmcs:compare, which only looks at invoices made after the import. */
    private function remember(): void
    {
        Setting::updateOrCreate(['key' => self::SETTING], ['value' => json_encode([
            'imported_at' => $this->now,
            'last_invoice_id' => (int) $this->source->table('tblinvoices')->max('id'),
        ])]);
    }

    /**
     * A date as Eloquent writes it on this database, so imported dates
     * compare equal to ones the app saves later (SQLite keeps them as text).
     */
    private function day(?string $date): ?string
    {
        return $date === null ? null : Carbon::parse($date)->format(DB::getQueryGrammar()->getDateFormat());
    }

    private function setting(string $name): ?string
    {
        if (! $this->hasTables('tblconfiguration')) {
            return null;
        }

        $value = $this->source->table('tblconfiguration')->where('setting', $name)->value('value');

        return $value === null ? null : (string) $value;
    }

    private function hasTables(string ...$tables): bool
    {
        foreach ($tables as $table) {
            if (! $this->source->getSchemaBuilder()->hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    private function name(object $row): string
    {
        return trim(Whmcs::text($row->firstname ?? '').' '.Whmcs::text($row->lastname ?? '')) ?: 'no name';
    }

    /** Product descriptions in WHMCS are HTML. */
    private function plain(string $html): string
    {
        return trim(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", Whmcs::text($html)) ?? ''));
    }

    /** @param  array<string, mixed>  $row */
    private function queue(string $table, array $row): void
    {
        $this->pending[$table][] = $row;

        if (count($this->pending[$table]) >= self::BATCH) {
            $this->flush($table);
        }
    }

    private function flush(string $table): void
    {
        if (($this->pending[$table] ?? []) !== []) {
            DB::table($table)->insert($this->pending[$table]);
        }

        $this->pending[$table] = [];
    }

    private function flushAll(): void
    {
        foreach (array_keys($this->pending) as $table) {
            $this->flush($table);
        }
    }

    private function removeCopiedFiles(): void
    {
        foreach ($this->copied as $path) {
            Storage::disk('local')->delete($path);
        }
    }
}
