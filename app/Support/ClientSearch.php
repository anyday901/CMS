<?php

namespace App\Support;

use App\Enums\ClientStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The admin clients list's search: a basic row (name, email, phone, status)
 * and advanced filters, read from the query string so results can be linked.
 * Closed clients are left out unless asked for.
 */
class ClientSearch
{
    private const NAME = 'name';

    private const PARTIAL = 'partial';

    private const EXACT = 'exact';

    private const STATUS = 'status';

    private const YES_NO = 'yes_no';

    private const FROM = 'from';

    private const TO = 'to';

    private const GROUP = 'group';

    /** The group filter's value for clients in no group. */
    public const NO_GROUP = 'none';

    /** Each filter in the query string and how it matches. */
    private const FILTERS = [
        'q' => self::NAME,
        'email' => self::PARTIAL,
        'phone' => self::PARTIAL,
        'status' => self::STATUS,
        'address1' => self::PARTIAL,
        'address2' => self::PARTIAL,
        'city' => self::PARTIAL,
        'state' => self::PARTIAL,
        'postcode' => self::PARTIAL,
        'country' => self::EXACT,
        'currency' => self::EXACT,
        'credit_balance' => self::YES_NO,
        'tax_exempt' => self::YES_NO,
        'marketing_emails' => self::YES_NO,
        'group' => self::GROUP,
        'signup_from' => self::FROM,
        'signup_to' => self::TO,
    ];

    /** Filters in the basic row; the rest live in the advanced panel. */
    private const BASIC = ['q', 'email', 'phone', self::STATUS];

    /** @param  array<string, string>  $filters */
    private function __construct(private readonly array $filters, private readonly bool $showClosed) {}

    public static function fromRequest(Request $request): self
    {
        $filters = [];
        foreach (self::FILTERS as $key => $kind) {
            $value = trim((string) $request->query($key));
            if ($value !== '' && self::valid($kind, $value)) {
                $filters[$key] = $value;
            }
        }

        return new self($filters, $request->boolean('show_closed'));
    }

    private static function valid(string $kind, string $value): bool
    {
        return match ($kind) {
            self::STATUS => ClientStatus::tryFrom($value) !== null,
            self::YES_NO => in_array($value, ['yes', 'no'], true),
            self::FROM, self::TO => self::validDate($value),
            self::GROUP => $value === self::NO_GROUP || ctype_digit($value),
            default => true,
        };
    }

    /** A real calendar date written as YYYY-MM-DD, so "2026-02-31" is ignored. */
    private static function validDate(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    public function get(string $key): string
    {
        return $this->filters[$key] ?? '';
    }

    /** @return array<string, string> */
    public function filters(): array
    {
        return $this->filters;
    }

    public function usesAdvanced(): bool
    {
        return array_diff_key($this->filters, array_flip(self::BASIC)) !== [];
    }

    public function showsClosed(): bool
    {
        return $this->showClosed;
    }

    /** Whether closed clients are left out: by default, unless shown or picked as the status. */
    public function hidesClosed(): bool
    {
        return ! $this->showClosed && ! isset($this->filters[self::STATUS]);
    }

    /** The query string for this search with closed clients shown or hidden. */
    public function withClosed(bool $show): array
    {
        return array_filter([...$this->filters, 'show_closed' => $show ? 1 : null]);
    }

    public function apply(Builder $query): Builder
    {
        return $this->applyFilters($query)
            ->when($this->hidesClosed(), fn ($q) => $q->where(self::STATUS, '!=', ClientStatus::Closed));
    }

    /** How many closed clients match the other filters, for the toggle's label. */
    public function closedCount(Builder $query): int
    {
        return $this->applyFilters($query)->where(self::STATUS, ClientStatus::Closed)->count();
    }

    private function applyFilters(Builder $query): Builder
    {
        foreach ($this->filters as $key => $value) {
            match (self::FILTERS[$key]) {
                self::NAME => $this->matchName($query, $value),
                self::PARTIAL => $query->where($key, 'like', '%'.$value.'%'),
                self::EXACT, self::STATUS => $query->where($key, $value),
                self::YES_NO => $this->matchYesNo($query, $key, $value === 'yes'),
                self::FROM => $query->whereDate('created_at', '>=', $value),
                self::TO => $query->whereDate('created_at', '<=', $value),
                self::GROUP => $value === self::NO_GROUP ? $query->whereNull('client_group_id') : $query->where('client_group_id', (int) $value),
            };
        }

        return $query;
    }

    /** Has a credit balance, or is tax exempt. */
    private function matchYesNo(Builder $query, string $column, bool $yes): void
    {
        if ($column === 'credit_balance') {
            $query->where($column, $yes ? '>' : '<=', 0);
        } else {
            $query->where($column, $yes);
        }
    }

    /** Every word must appear in the first name, last name or company, so "jane doe" finds Jane Doe. */
    private function matchName(Builder $query, string $value): void
    {
        foreach (preg_split('/\s+/', $value) as $word) {
            $query->where(fn ($q) => $q
                ->where('first_name', 'like', '%'.$word.'%')
                ->orWhere('last_name', 'like', '%'.$word.'%')
                ->orWhere('company', 'like', '%'.$word.'%'));
        }
    }
}
