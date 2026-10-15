<?php

namespace App\Reports;

use App\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The dates, currency, client and options a report runs for, read from the report's form. */
class ReportFilters
{
    /** The longest range a report runs over, so a typo can't ask for centuries of days. */
    private const MAX_YEARS = 25;

    /** @param  array<string, string>  $options */
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $currency,
        public readonly ?Client $client = null,
        public readonly array $options = [],
    ) {}

    public static function fromRequest(Request $request, Report $report): self
    {
        $today = CarbonImmutable::today();
        [$defaultFrom, $defaultTo] = $report->defaultRange($today);
        $from = self::date($request->query('from')) ?? $defaultFrom;
        $to = self::date($request->query('to')) ?? $defaultTo;

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->lessThan($to->subYears(self::MAX_YEARS))) {
            $from = $to->subYears(self::MAX_YEARS);
        }

        $currencies = self::currencies();
        $currency = strtoupper((string) $request->query('currency'));

        $options = [];
        foreach ($report->options() as $name => [, $choices]) {
            $value = (string) $request->query($name);
            $options[$name] = array_key_exists($value, $choices) ? $value : (string) array_key_first($choices);
        }

        $client = in_array(Report::CLIENT, $report->filters(), true) ? self::client((string) $request->query('client')) : null;

        return new self(
            $from->startOfDay(),
            $to->startOfDay(),
            in_array($currency, $currencies, true) ? $currency : $currencies[0],
            $client,
            $options,
        );
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} the first and last moment of the range, for whereBetween */
    public function between(): array
    {
        return [$this->from->startOfDay(), $this->to->endOfDay()];
    }

    public function option(string $name): string
    {
        return $this->options[$name] ?? '';
    }

    /** @return array<string, string> what the form sends, for links and the CSV export */
    public function query(): array
    {
        return array_filter([
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'currency' => $this->currency,
            'client' => $this->client ? (string) $this->client->id : null,
        ] + $this->options, fn ($value) => $value !== null && $value !== '');
    }

    /**
     * The currencies with clients or payments, the billing currency first.
     *
     * @return non-empty-list<string>
     */
    public static function currencies(): array
    {
        $main = strtoupper((string) config('billing.currency'));
        $used = DB::table('clients')->distinct()->pluck('currency')
            ->merge(DB::table('transactions')->distinct()->pluck('currency'))
            ->map(fn ($code) => strtoupper((string) $code))
            ->filter(fn (string $code) => $code !== '' && $code !== $main)
            ->unique()->sort()->values()->all();

        return [$main, ...$used];
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** A client by id or email address. */
    private static function client(string $value): ?Client
    {
        $value = trim($value);

        return match (true) {
            $value === '' => null,
            ctype_digit($value) => Client::find((int) $value),
            default => Client::where('email', strtolower($value))->first(),
        };
    }
}
