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

    protected $fillable = [
        'invoice_id', 'service_id', 'type', 'description', 'amount', 'taxable',
        'period_start', 'period_end',
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
}
