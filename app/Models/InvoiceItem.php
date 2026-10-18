<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class InvoiceItem extends Model
{
    use HasFactory;

    public const TYPE_MANUAL = 'manual';

    public const TYPE_SERVICE = 'service';

    public const TYPE_SETUP_FEE = 'setup_fee';

    public const TYPE_LATE_FEE = 'late_fee';

    /** A promotion code's discount on a service line, as a negative amount. */
    public const TYPE_PROMOTION = 'promotion';

    /** The prorated charge for moving a service to a dearer product. */
    public const TYPE_UPGRADE = 'upgrade';

    /** Disk or bandwidth used over what the product includes, billed after the month. */
    public const TYPE_OVERAGE = 'overage';

    /** A domain registered, transferred or renewed for the item's period. */
    public const TYPE_DOMAIN_REGISTER = 'domain_register';

    public const TYPE_DOMAIN_TRANSFER = 'domain_transfer';

    public const TYPE_DOMAIN_RENEW = 'domain_renew';

    /** A billable item staff added to the client, see BillableItem. */
    public const TYPE_BILLABLE = 'billable';

    public const DOMAIN_TYPES = [self::TYPE_DOMAIN_REGISTER, self::TYPE_DOMAIN_TRANSFER, self::TYPE_DOMAIN_RENEW];

    /** Money the client prepays into their credit balance. */
    public const TYPE_ADD_FUNDS = 'add_funds';

    /** A mass payment's line for one of the invoices it pays, which paid_invoice_id points at. */
    public const TYPE_MASS_PAY = 'mass_pay';

    /** Lines whose payment goes to the client's credit balance rather than paying for anything. */
    public const FUNDS_TYPES = [self::TYPE_ADD_FUNDS, self::TYPE_MASS_PAY];

    protected $fillable = [
        'invoice_id', 'service_id', 'domain_id', 'type', 'description', 'amount', 'taxable',
        'period_start', 'period_end', 'billable_item_id', 'paid_invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'taxable' => 'boolean',
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    protected $attributes = ['taxable' => true];

    /** "Web hosting - example.com (01/01/2026 - 01/31/2026), Disk: 50 GB, Backups", cut to fit the column. */
    public static function serviceDescription(Service $service, CarbonInterface $start, ?CarbonInterface $end): string
    {
        $description = $end
            ? sprintf('%s (%s - %s)', $service->description(), $start->format('m/d/Y'), $end->format('m/d/Y'))
            : $service->description();
        $options = $service->optionSummary();

        return Str::limit($options === '' ? $description : "{$description}, {$options}", 255, '');
    }

    /** "Promotion code SAVE10: Web hosting - example.com" */
    public static function promotionDescription(Service $service, ?string $code): string
    {
        return Str::limit(($code ? "Promotion code {$code}" : 'Discount').": {$service->description()}", 255, '');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function billableItem(): BelongsTo
    {
        return $this->belongsTo(BillableItem::class);
    }

    /** The invoice a mass payment line pays. */
    public function paidInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'paid_invoice_id');
    }
}
