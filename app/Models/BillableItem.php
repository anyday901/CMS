<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A charge staff add to a client, like WHMCS's billable items: a one-off
 * amount (or hours at a rate) or one that recurs, which the billing run puts
 * on an invoice according to its invoice action. A one-off item is invoiced
 * once; a recurring one each time it comes due, until it has been invoiced
 * recur_times times (or for ever, when that is empty).
 */
class BillableItem extends Model
{
    /** Kept on the client until staff pick another action. */
    public const DONT_INVOICE = 'none';

    /** Invoiced by the next billing run. */
    public const NEXT_CRON = 'next_cron';

    /** Added to the next renewal invoice the billing run makes for the client. */
    public const NEXT_INVOICE = 'next_invoice';

    /** Invoiced when the due date comes within the days renewals are invoiced ahead. */
    public const DUE_DATE = 'due_date';

    /** Invoiced on the due date, then every recur_every recur_units after it. */
    public const RECUR = 'recur';

    public const ACTIONS = [
        self::DONT_INVOICE => 'Don\'t invoice',
        self::NEXT_CRON => 'Invoice on the next billing run',
        self::NEXT_INVOICE => 'Add to the next renewal invoice',
        self::DUE_DATE => 'Invoice on the due date',
        self::RECUR => 'Recur',
    ];

    public const UNITS = ['day' => 'days', 'week' => 'weeks', 'month' => 'months', 'year' => 'years'];

    protected $fillable = [
        'description', 'hours', 'rate', 'amount', 'taxable', 'invoice_action', 'due_date',
        'recur_every', 'recur_unit', 'recur_times',
    ];

    protected $attributes = ['taxable' => true, 'invoice_action' => self::DONT_INVOICE, 'invoiced_count' => 0];

    protected function casts(): array
    {
        return [
            'hours' => 'decimal:2',
            'rate' => 'integer',
            'amount' => 'integer',
            'taxable' => 'boolean',
            'due_date' => 'date',
            'recur_every' => 'integer',
            'recur_times' => 'integer',
            'invoiced_count' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** The staff member who added it. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The invoice it last went on. */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** Its lines on invoices, one per time it was invoiced. */
    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * Items the billing run invoices by $cutoff (today plus the days renewals
     * are invoiced ahead): ones set for the next run, and ones whose due date
     * has come within reach. Items waiting for the next renewal invoice
     * aren't among them.
     *
     * @param  Builder<self>  $query
     */
    public function scopeDueBy(Builder $query, CarbonInterface $cutoff): void
    {
        $query->where(fn (Builder $query) => $query
            ->where(fn (Builder $once) => $once->whereNull('invoice_id')->where(fn (Builder $when) => $when
                ->where('invoice_action', self::NEXT_CRON)
                ->orWhere(fn (Builder $dated) => $dated->where('invoice_action', self::DUE_DATE)->whereDate('due_date', '<=', $cutoff))))
            ->orWhere(fn (Builder $recurring) => $recurring->where('invoice_action', self::RECUR)
                ->whereDate('due_date', '<=', $cutoff)
                ->where(fn (Builder $left) => $left->whereNull('recur_times')->orWhereColumn('invoiced_count', '<', 'recur_times'))));
    }

    /**
     * Items waiting to go on the client's next renewal invoice.
     *
     * @param  Builder<self>  $query
     */
    public function scopeForNextInvoice(Builder $query): void
    {
        $query->where('invoice_action', self::NEXT_INVOICE)->whereNull('invoice_id');
    }

    public function isRecurring(): bool
    {
        return $this->invoice_action === self::RECUR;
    }

    /** A recurring item invoiced as many times as it was set to. */
    public function isFinished(): bool
    {
        return $this->isRecurring() && $this->recur_times !== null && $this->invoiced_count >= $this->recur_times;
    }

    /**
     * Done with: a one-off item on an invoice that wasn't cancelled, or a
     * recurring one that has finished. Until then staff can change it.
     */
    public function isInvoiced(): bool
    {
        if ($this->isRecurring()) {
            return $this->isFinished();
        }

        return $this->invoice_id !== null && $this->invoice?->status !== InvoiceStatus::Cancelled;
    }

    /** "Every 2 months, 3 of 12 invoiced" */
    public function recurrence(): string
    {
        $unit = $this->recur_every === 1 ? $this->recur_unit : self::UNITS[$this->recur_unit] ?? $this->recur_unit;
        $every = $this->recur_every === 1 ? "Every {$unit}" : "Every {$this->recur_every} {$unit}";

        return $every.', '.($this->recur_times === null ? "{$this->invoiced_count} invoiced so far" : "{$this->invoiced_count} of {$this->recur_times} invoiced");
    }

    /** The date after $date that a recurring item next comes due. Month ends don't overflow. */
    public function after(CarbonInterface $date): CarbonInterface
    {
        $every = max(1, (int) $this->recur_every);

        return match ($this->recur_unit) {
            'day' => $date->copy()->addDays($every),
            'week' => $date->copy()->addWeeks($every),
            'month' => $date->copy()->addMonthsNoOverflow($every),
            'year' => $date->copy()->addYearsNoOverflow($every),
            default => throw new InvalidArgumentException("Unknown recurring unit: {$this->recur_unit}"),
        };
    }

    /** The invoice line: "Consulting (1.50 hours at $75.00/hr)", with the due date for a recurring item. */
    public function lineDescription(string $currency): string
    {
        $extra = array_filter([
            $this->hours !== null && $this->rate !== null ? "{$this->hours} hours at ".Money::format($this->rate, $currency).'/hr' : null,
            $this->isRecurring() && $this->due_date ? $this->due_date->format('m/d/Y') : null,
        ]);

        return Str::limit($extra === [] ? $this->description : $this->description.' ('.implode(', ', $extra).')', 255, '');
    }

    /** Cents for hours like "1.5" at an hourly rate, rounded half up, without floats. */
    public static function cost(string $hours, int $hourlyRate): int
    {
        if (! preg_match('/^(\d{1,6})(?:\.(\d{1,2}))?$/', trim($hours), $m)) {
            throw new InvalidArgumentException("Invalid hours: {$hours}");
        }

        $hundredths = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');

        return intdiv($hundredths * $hourlyRate * 2 + 100, 200);
    }
}
