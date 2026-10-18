<?php

namespace App\Support;

use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The admin search box: one query across clients (and their contacts),
 * services, domains, invoices, quotes, orders and tickets. Numbers match ids and
 * invoice, quote, order and ticket numbers exactly; words match names, emails,
 * domains and subjects. Each section is limited, and staff only see the
 * sections their role can open.
 */
class GlobalSearch
{
    public const LIMIT = 15;

    /** @var list<string> */
    private array $words;

    private string $like;

    private ?int $number;

    public function __construct(private string $query)
    {
        $this->query = trim($query);
        $this->like = '%'.addcslashes($this->query, '%_\\').'%';
        $this->words = array_values(array_filter(preg_split('/\s+/', $this->query) ?: []));
        $digits = ltrim($this->query, '#');
        $this->number = ctype_digit($digits) && strlen($digits) < 10 ? (int) $digits : null;
    }

    public function tooShort(): bool
    {
        return mb_strlen($this->query) < 2 && $this->number === null;
    }

    /**
     * Each section's results and how many matched in all.
     *
     * @return array<string, array{items: Collection, total: int}>
     */
    public function results(User $staff): array
    {
        if ($this->tooShort()) {
            return [];
        }

        $sections = [
            'clients' => $this->clients(),
            'services' => $this->services(),
            'domains' => $this->domains(),
            'invoices' => $this->invoices(),
            'quotes' => $this->quotes(),
            'orders' => $this->orders(),
        ];

        if ($staff->can('manage-tickets')) {
            $sections['tickets'] = $this->tickets();
        }

        return array_map(fn (Builder $query) => [
            'total' => (clone $query)->count(),
            'items' => $query->limit(self::LIMIT)->get(),
        ], $sections);
    }

    /** Clients by name, company, email, phone or number, and clients whose contacts match. */
    private function clients(): Builder
    {
        $contacts = ClientContact::query()->select('client_id')->where(fn (Builder $query) => $this->eachWord($query, ['first_name', 'last_name'])
            ->orWhere('email', 'like', $this->like));

        return Client::with('group')
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $this->eachWord($query, ['first_name', 'last_name', 'company']))
                ->orWhere('email', 'like', $this->like)
                ->orWhere('phone', 'like', $this->like)
                ->orWhereIn('id', $contacts)
                ->when($this->number, fn (Builder $query, int $id) => $query->orWhere('id', $id)))
            ->orderBy('last_name')->orderBy('first_name');
    }

    /** Registered and transferred domains by name or number. */
    private function domains(): Builder
    {
        return Domain::with('client')
            ->where(fn (Builder $query) => $query
                ->where('name', 'like', $this->like)
                ->when($this->number, fn (Builder $query, int $id) => $query->orWhere('id', $id)))
            ->orderBy('name');
    }

    /** Services by domain or label, control panel username, product name or number. */
    private function services(): Builder
    {
        return Service::with(['product', 'client'])
            ->where(fn (Builder $query) => $query
                ->where('label', 'like', $this->like)
                ->orWhere('username', 'like', $this->like)
                ->orWhereHas('product', fn (Builder $query) => $query->where('name', 'like', $this->like))
                ->when($this->number, fn (Builder $query, int $id) => $query->orWhere('id', $id)))
            ->latest('id');
    }

    private function invoices(): Builder
    {
        return Invoice::with('client')
            ->where(fn (Builder $query) => $query
                ->where('number', $this->query)
                ->when($this->number, fn (Builder $query, int $id) => $query->orWhere('number', (string) $id)->orWhere('id', $id))
                ->orWhere('notes', 'like', $this->like))
            ->latest('issue_date')->latest('id');
    }

    /** Quotes by number or subject. */
    private function quotes(): Builder
    {
        return Quote::with('client')
            ->where(fn (Builder $query) => $query
                ->where('number', ltrim($this->query, '#'))
                ->orWhere('subject', 'like', $this->like))
            ->latest('issue_date')->latest('id');
    }

    private function orders(): Builder
    {
        return Order::with(['client', 'invoice', 'services.product'])
            ->when($this->number, fn (Builder $query, int $id) => $query->where(fn (Builder $query) => $query->whereKey($id)->orWhere('whmcs_number', (string) $id)), fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->latest('id');
    }

    /** Tickets by number, subject, or the email of a guest who opened one. */
    private function tickets(): Builder
    {
        return Ticket::with(['client', 'department', 'assignee', 'tags'])
            ->whereNull('merged_into_id')
            ->where(fn (Builder $query) => $query
                ->where('subject', 'like', $this->like)
                ->orWhere('guest_email', 'like', $this->like)
                ->when($this->number, fn (Builder $query, int $id) => $query->orWhere('id', $id)))
            ->latest('last_reply_at')->latest('id');
    }

    /**
     * Every word in at least one of the columns, so "jane doe" finds Jane Doe.
     *
     * @param  list<string>  $columns
     */
    private function eachWord(Builder $query, array $columns): Builder
    {
        foreach ($this->words as $word) {
            $like = '%'.addcslashes($word, '%_\\').'%';
            $query->where(function (Builder $query) use ($columns, $like) {
                foreach ($columns as $column) {
                    $query->orWhere($column, 'like', $like);
                }
            });
        }

        return $query;
    }
}
