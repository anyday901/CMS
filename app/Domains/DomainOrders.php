<?php

namespace App\Domains;

use App\Enums\DomainStatus;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Tld;
use App\Models\TldPrice;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Prices domain registrations, transfers and renewals, and puts ordered ones on the order's invoice. */
class DomainOrders
{
    /** Statuses that keep a name from being ordered again. */
    private const HELD = [DomainStatus::Pending, DomainStatus::PendingTransfer, DomainStatus::Active, DomainStatus::Expired];

    /**
     * Checks a cart line can be ordered in this currency and prices it.
     * $data is ['name', 'type', 'years', 'id_protection', 'epp_code', 'nameservers'].
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException
     */
    public function line(array $data, string $currency): DomainLine
    {
        $parsed = DomainName::parse((string) ($data['name'] ?? ''))
            ?? throw new InvalidArgumentException(__(':name is no longer for sale. Remove it to carry on.', ['name' => (string) ($data['name'] ?? '')]));
        $tld = $parsed->tld->loadMissing('prices');
        $name = $parsed->full();
        $type = in_array($data['type'] ?? null, [Domain::REGISTER, Domain::TRANSFER], true) ? $data['type'] : Domain::REGISTER;
        $years = (int) ($data['years'] ?? 1);
        if ($type === Domain::TRANSFER && $years !== 1) {
            throw new InvalidArgumentException(__('Transfers are for 1 year: the registrar adds a year to :name.', ['name' => $name]));
        }
        $price = $tld->priceFor($currency, $years);

        if ($price?->{$type} === null) {
            throw new InvalidArgumentException($type === Domain::TRANSFER
                ? __("Transfers of :tld domains for :years year(s) aren't sold in :currency.", ['tld' => $tld->label(), 'years' => $years, 'currency' => $currency])
                : __(":tld domains aren't sold for :years year(s) in :currency.", ['tld' => $tld->label(), 'years' => $years, 'currency' => $currency]));
        }
        if (self::isTaken($name)) {
            throw new InvalidArgumentException(__(':name is already registered with us.', ['name' => $name]));
        }

        $eppCode = trim((string) ($data['epp_code'] ?? ''));
        if ($type === Domain::TRANSFER && $tld->epp_required && $eppCode === '') {
            throw new InvalidArgumentException(__('Enter the EPP code for :name. Its current registrar gives it to you.', ['name' => $name]));
        }

        $protection = $tld->id_protection && ! empty($data['id_protection']);
        $renewal = self::renewalAmount($tld, $currency, $years)
            ?? throw new InvalidArgumentException(__(':tld domains have no renewal price in :currency.', ['tld' => $tld->label(), 'currency' => $currency]));

        return new DomainLine(
            $name,
            $tld,
            $type,
            $years,
            $protection,
            $type === Domain::TRANSFER && $eppCode !== '' ? $eppCode : null,
            array_values(array_filter((array) ($data['nameservers'] ?? []))),
            $price->{$type} + ($protection ? $price->id_protection : 0),
            $renewal + ($protection ? $price->id_protection : 0),
        );
    }

    /** Whether the name is already with us, ordered or registered. */
    public static function isTaken(string $name): bool
    {
        return Domain::where('name', $name)->whereIn('status', self::HELD)->exists();
    }

    /**
     * What renewing for $years costs: that term's renewal price, else the
     * one year renewal price times the years.
     */
    public static function renewalAmount(Tld $tld, string $currency, int $years): ?int
    {
        $renew = $tld->priceFor($currency, $years)?->renew;
        $yearly = $tld->priceFor($currency, 1)?->renew;

        return $renew ?? ($yearly !== null ? $yearly * $years : null);
    }

    /** The ID protection price for a term, 0 when it's free or not set. */
    public static function protectionAmount(Tld $tld, string $currency, int $years): int
    {
        $price = $tld->priceFor($currency, $years);
        $yearly = $tld->priceFor($currency, 1);

        return $price instanceof TldPrice ? $price->id_protection : (int) $yearly?->id_protection * $years;
    }

    /**
     * Creates a pending domain for each line, charged on $invoice. They are
     * registered or transferred once it's paid.
     *
     * @param  list<DomainLine>  $lines
     * @return list<Domain>
     */
    public function addToInvoice(Client $client, Invoice $invoice, array $lines, ?Order $order, CarbonInterface $start): array
    {
        $domains = [];

        foreach ($lines as $line) {
            $domain = Domain::create([
                'client_id' => $client->id,
                'order_id' => $order?->id,
                'tld_id' => $line->tld->id,
                'name' => $line->name,
                'registrar' => $line->tld->registrar,
                'type' => $line->type,
                'status' => DomainStatus::Pending,
                'years' => $line->years,
                'recurring_amount' => $line->renewal,
                'auto_renew' => (bool) config('domains.auto_renew_default'),
                'id_protection' => $line->idProtection,
                'nameservers' => $line->nameservers ?: self::defaultNameservers(),
                'epp_code' => $line->eppCode,
            ]);
            $end = $start->copy()->addYears($line->years)->subDay();

            $invoice->items()->create([
                'domain_id' => $domain->id,
                'type' => $line->type === Domain::TRANSFER ? InvoiceItem::TYPE_DOMAIN_TRANSFER : InvoiceItem::TYPE_DOMAIN_REGISTER,
                'description' => self::description($domain, $line->type, $line->years, $start, $end),
                'amount' => $line->amount,
                'period_start' => $start,
                'period_end' => $end,
            ]);
            Activity::record(($line->type === Domain::TRANSFER ? 'Ordered a transfer of ' : 'Ordered ')."{$domain->name} for {$line->years} year(s) at ".Money::format($line->amount, $invoice->currency), $domain);
            $domains[] = $domain;
        }

        return $domains;
    }

    /** "Domain registration: example.com, 1 year (10/16/2026 - 10/15/2027), with ID protection" */
    public static function description(Domain $domain, string $type, int $years, CarbonInterface $start, CarbonInterface $end): string
    {
        $what = match ($type) {
            Domain::TRANSFER => 'Domain transfer',
            'renew' => 'Domain renewal',
            default => 'Domain registration',
        };

        return Str::limit(sprintf(
            '%s: %s, %d year%s (%s - %s)%s',
            $what,
            $domain->name,
            $years,
            $years === 1 ? '' : 's',
            $start->format('m/d/Y'),
            $end->format('m/d/Y'),
            $domain->id_protection ? ', with ID protection' : '',
        ), 255, '');
    }

    /** @return list<string> */
    public static function defaultNameservers(): array
    {
        return array_values(array_filter(array_map('trim', (array) config('domains.nameservers'))));
    }
}
