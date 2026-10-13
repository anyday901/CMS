<?php

namespace App\Import\Whmcs;

use App\Enums\BillingCycle;
use App\Models\CustomField;
use App\Models\ProductOption;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Products with their prices, configurable options and custom fields, plus
 * the custom field values that clients and services pick up later.
 */
class CatalogImporter
{
    private const PRODUCTS = 'Products';

    private const OPTIONS = 'Configurable options';

    private const FIELDS = 'Custom fields';

    /** @var array<int, array{id: int, onetime: bool, module: ?string}> WHMCS product id => local product */
    public array $products = [];

    /** @var array<string, array{id: int, type: string, choices: array<int, array{id: int, prices: array<string, int>}>}> "product:option" => local option */
    public array $options = [];

    /** @var array<int, array<int, string>> client id => [local field id => value] */
    public array $clientValues = [];

    /** @var array<int, array<int, string>> service id => [local field id => value] */
    public array $serviceValues = [];

    /** @var array<int, array{id: int, type: string, entity: string}> WHMCS field id => local field */
    private array $fields = [];

    public function __construct(private ImportRun $run, private ServerImporter $servers) {}

    public function import(): void
    {
        $this->importProducts();
        $this->importOptions();
        $this->importCustomFields();
        $this->importFieldValues();
    }

    private function importProducts(): void
    {
        $prices = $this->run->table('tblpricing')->where('type', 'product')->get()->groupBy('relid');

        foreach ($this->run->table('tblproducts')->orderBy('id')->cursor() as $row) {
            $this->run->report->found(self::PRODUCTS);
            $onetime = strtolower((string) $row->paytype) === 'onetime';
            $module = $this->servers->product($row);

            $id = DB::table('products')->insertGetId([
                'name' => Whmcs::text($row->name),
                'description' => Whmcs::plain($row->description ?? '') ?: null,
                'active' => ! Whmcs::on($row->hidden ?? '') && ! Whmcs::on($row->retired ?? ''),
                'taxable' => Whmcs::on($row->tax ?? ''),
                ...($module ?? []),
                'created_at' => $this->run->now,
                'updated_at' => $this->run->now,
            ]);

            $this->products[(int) $row->id] = ['id' => $id, 'onetime' => $onetime, 'module' => $module['module'] ?? null];
            $this->run->report->imported(self::PRODUCTS);

            if ($module === null && filled($row->servertype ?? null)) {
                $this->run->report->note(self::PRODUCTS, "\"{$row->name}\" used the WHMCS {$row->servertype} module. Pick a provisioning module for it on its product page.");
            }

            if (strtolower((string) $row->paytype) !== 'free') {
                $this->importPrices($id, $prices->get($row->id, collect()), $onetime);
            }
        }
    }

    /** @param  Collection<int, object>  $prices  tblpricing rows, one per currency */
    private function importPrices(int $productId, Collection $prices, bool $onetime): void
    {
        foreach ($prices as $price) {
            foreach ($this->pricesFrom($price, $onetime) as $cycle => [$amount, $setup]) {
                DB::table('product_prices')->insert([
                    'product_id' => $productId,
                    'billing_cycle' => $cycle,
                    'currency' => $this->run->currency($price->currency),
                    'price' => $amount,
                    'setup_fee' => $setup,
                    'created_at' => $this->run->now,
                    'updated_at' => $this->run->now,
                ]);
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
        if (! $this->run->hasTables('tblproductconfiglinks', 'tblproductconfigoptions', 'tblproductconfigoptionssub')) {
            return;
        }

        $currencyId = array_search($this->run->billingCurrency, $this->run->currencies, true);
        $prices = $this->run->table('tblpricing')->where('type', 'configoptions')
            ->where('currency', $currencyId === false ? -1 : $currencyId)->get()->keyBy('relid');
        $options = $this->run->table('tblproductconfigoptions')->orderBy('order')->orderBy('id')->get()->groupBy('gid');
        $choices = $this->run->table('tblproductconfigoptionssub')->orderBy('sortorder')->orderBy('id')->get()->groupBy('configid');

        if ($currencyId === false && $prices->isEmpty()) {
            $this->run->report->note(self::OPTIONS, "WHMCS has no {$this->run->billingCurrency} currency, so option prices were left blank.");
        }

        foreach ($this->run->table('tblproductconfiglinks')->orderBy('id')->cursor() as $link) {
            $product = $this->products[(int) $link->pid] ?? null;

            foreach ($product === null ? [] : $options->get($link->gid, collect()) as $option) {
                $this->options["{$link->pid}:{$option->id}"] = $this->importOption($product, $option, $choices->get($option->id, collect()), $prices);
            }
        }
    }

    /**
     * @param  array{id: int, onetime: bool}  $product
     * @param  Collection<int, object>  $subs  the option's choices
     * @param  Collection<int|string, object>  $prices  tblpricing rows by choice id
     * @return array{id: int, type: string, choices: array<int, array{id: int, prices: array<string, int>}>}
     */
    private function importOption(array $product, object $option, Collection $subs, Collection $prices): array
    {
        $this->run->report->found(self::OPTIONS);
        $name = Whmcs::label($option->optionname);
        $type = match ((int) $option->optiontype) {
            3 => ProductOption::CHECKBOX,
            4 => ProductOption::QUANTITY,
            default => ProductOption::DROPDOWN,
        };

        $optionId = DB::table('product_options')->insertGetId([
            'product_id' => $product['id'],
            'name' => $name,
            'type' => $type,
            'min_quantity' => max(0, (int) ($option->qtyminimum ?? 0)),
            'max_quantity' => ((int) ($option->qtymaximum ?? 0)) ?: null,
            'sort_order' => (int) ($option->order ?? 0),
            'created_at' => $this->run->now,
            'updated_at' => $this->run->now,
        ]);

        if ($type !== ProductOption::DROPDOWN && $subs->count() > 1) {
            $this->run->report->note(self::OPTIONS, '"'.$name.'" had '.$subs->count().' choices; only the first was kept, since a '.$type.' has one.');
            $subs = $subs->take(1);
        }

        $local = ['id' => $optionId, 'type' => $type, 'choices' => []];

        foreach ($subs as $sub) {
            $local['choices'][(int) $sub->id] = $this->importChoice($optionId, $name, $sub, $prices->get($sub->id), $product['onetime']);
        }

        $this->run->report->imported(self::OPTIONS);

        return $local;
    }

    /** @return array{id: int, prices: array<string, int>} */
    private function importChoice(int $optionId, string $optionName, object $sub, ?object $price, bool $onetime): array
    {
        $cyclePrices = [];
        $setupFee = 0;

        if ($price !== null) {
            $cyclePrices = $this->optionPrices($price, $onetime);
            $setupFee = max(0, Whmcs::cents($price->msetupfee));

            if ($this->setupFeesDiffer($price)) {
                $this->run->report->note(self::OPTIONS, '"'.$optionName.': '.Whmcs::label($sub->optionname).'" has a different setup fee per billing cycle in WHMCS. Options have one setup fee here, so the monthly one was kept.');
            }
        }

        $id = DB::table('product_option_choices')->insertGetId([
            'product_option_id' => $optionId,
            'name' => Whmcs::label($sub->optionname),
            'prices' => $cyclePrices === [] ? null : json_encode($cyclePrices),
            'setup_fee' => $setupFee,
            'sort_order' => (int) ($sub->sortorder ?? 0),
            'created_at' => $this->run->now,
            'updated_at' => $this->run->now,
        ]);

        return ['id' => $id, 'prices' => $cyclePrices];
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
        if (! $this->run->hasTables('tblcustomfields')) {
            return;
        }

        foreach ($this->run->table('tblcustomfields')->whereIn('type', ['client', 'product'])->orderBy('sortorder')->orderBy('id')->cursor() as $row) {
            $this->run->report->found(self::FIELDS);
            $entity = $row->type === 'client' ? CustomField::CLIENT : CustomField::SERVICE;
            $productId = $entity === CustomField::SERVICE ? ($this->products[(int) $row->relid]['id'] ?? 0) : null;

            if ($productId === 0) {
                $this->run->report->skipped(self::FIELDS, "\"{$row->fieldname}\" belongs to a product that wasn't found.");

                continue;
            }

            $type = $this->fieldType($row->fieldtype);

            $id = DB::table('custom_fields')->insertGetId([
                'entity' => $entity,
                'product_id' => $productId,
                'name' => Str::limit(Whmcs::label($row->fieldname), 250, ''),
                'type' => $type,
                'options' => $type === 'dropdown' ? $this->dropdownOptions($row->fieldoptions) : null,
                'help' => Whmcs::short($row->description ?? ''),
                'required' => Whmcs::on($row->required ?? ''),
                'admin_only' => Whmcs::on($row->adminonly ?? ''),
                'sort_order' => (int) ($row->sortorder ?? 0),
                'created_at' => $this->run->now,
                'updated_at' => $this->run->now,
            ]);

            $this->fields[(int) $row->id] = ['id' => $id, 'type' => $type, 'entity' => $entity];
            $this->run->report->imported(self::FIELDS);
        }
    }

    private function fieldType(mixed $whmcsType): string
    {
        return match (strtolower((string) $whmcsType)) {
            'textarea' => 'textarea',
            'dropdown' => 'dropdown',
            'tickbox' => CustomField::CHECKBOX,
            default => 'text',
        };
    }

    /** WHMCS keeps dropdown choices comma separated; here they are one per line. */
    private function dropdownOptions(mixed $options): string
    {
        return implode("\n", array_filter(array_map(Whmcs::text(...), explode(',', (string) $options))));
    }

    private function importFieldValues(): void
    {
        if ($this->fields === [] || ! $this->run->hasTables('tblcustomfieldsvalues')) {
            return;
        }

        foreach ($this->run->table('tblcustomfieldsvalues')->whereIn('fieldid', array_keys($this->fields))->cursor() as $value) {
            $field = $this->fields[(int) $value->fieldid];
            $text = Whmcs::text($value->value);

            if ($field['type'] === CustomField::CHECKBOX) {
                $text = Whmcs::on($value->value) ? '1' : '';
            }

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
}
