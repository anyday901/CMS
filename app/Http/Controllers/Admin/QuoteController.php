<?php

namespace App\Http\Controllers\Admin;

use App\Billing\QuoteConverter;
use App\Billing\QuoteEditor;
use App\Billing\QuotePdf;
use App\Enums\QuoteStage;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Quote;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class QuoteController extends Controller
{
    public function index(Request $request): View
    {
        $stage = QuoteStage::tryFrom((string) $request->query('stage'))?->value;
        $search = trim((string) $request->query('q'));

        $quotes = Quote::query()
            ->with('client')
            ->when($stage, fn (Builder $query, string $stage) => $query->where('stage', $stage))
            ->when($search !== '', function (Builder $query) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn (Builder $query) => $query
                    ->where('number', ltrim($search, '#'))
                    ->orWhere('subject', 'like', $like)
                    ->orWhereHas('client', fn (Builder $query) => $query
                        ->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('company', 'like', $like)
                        ->orWhere('email', 'like', $like)));
            })
            ->latest('issue_date')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.quotes.index', compact('quotes', 'stage', 'search'));
    }

    public function show(Quote $quote): View
    {
        $quote->load(['client', 'items', 'invoice']);

        return view('admin.quotes.show', [
            'quote' => $quote,
            'activity' => Activity::where('subject_type', $quote->getMorphClass())->where('subject_id', $quote->id)->latest('id')->limit(20)->get(),
        ]);
    }

    public function create(Client $client): View
    {
        return view('admin.quotes.form', [
            'client' => $client,
            'quote' => new Quote(['issue_date' => today(), 'valid_until' => today()->addDays(Quote::VALID_DAYS), 'stage' => QuoteStage::Draft]),
            'items' => collect(),
        ]);
    }

    public function store(Request $request, Client $client, QuoteEditor $editor): RedirectResponse
    {
        try {
            $quote = $editor->create($client, $this->validatedQuote($request));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        return redirect()->route('admin.quotes.show', $quote)->with('status', 'Quote created.');
    }

    public function edit(Quote $quote): View|RedirectResponse
    {
        if (! $quote->isEditable()) {
            return redirect()->route('admin.quotes.show', $quote)->with('status', 'This quote has become an invoice, so it can no longer be edited.');
        }

        return view('admin.quotes.form', ['client' => $quote->client, 'quote' => $quote, 'items' => $quote->items()->orderBy('id')->get()]);
    }

    public function update(Request $request, Quote $quote, QuoteEditor $editor): RedirectResponse
    {
        try {
            $editor->update($quote, $this->validatedQuote($request));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        return redirect()->route('admin.quotes.show', $quote)->with('status', 'Quote updated.');
    }

    public function duplicate(Quote $quote, QuoteEditor $editor): RedirectResponse
    {
        $copy = $editor->duplicate($quote);

        return redirect()->route('admin.quotes.edit', $copy)->with('status', "Copied quote #{$quote->number}. This is the new draft.");
    }

    public function send(Quote $quote, QuoteEditor $editor): RedirectResponse
    {
        try {
            $email = $editor->send($quote);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['quote' => $e->getMessage()]);
        }

        return back()->with('status', $email
            ? "Quote emailed to {$email->to}. The client can now see it in the portal."
            : 'The quote is marked delivered and the client can see it in the portal, but no email went out: the Quote delivered template is off or the client has no email address.');
    }

    public function convert(Quote $quote, QuoteConverter $converter): RedirectResponse
    {
        try {
            $invoice = $converter->convert($quote);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['quote' => $e->getMessage()]);
        }

        return redirect()->route('admin.invoices.show', $invoice)->with('status', "Quote #{$quote->number} is now this invoice.");
    }

    public function destroy(Quote $quote, QuoteEditor $editor): RedirectResponse
    {
        $client = $quote->client;
        $editor->delete($quote);

        return redirect()->route('admin.clients.show', [$client, 'tab' => 'quotes'])->with('status', "Quote #{$quote->number} deleted.");
    }

    public function pdf(Quote $quote, QuotePdf $pdf): Response
    {
        return $pdf->download($quote);
    }

    /** Validates the quote form, dropping blank item rows and converting amounts to cents. */
    private function validatedQuote(Request $request): array
    {
        $rows = collect($request->input('items', []))
            ->filter(fn ($row) => is_array($row) && (filled($row['description'] ?? null) || filled($row['amount'] ?? null)))
            ->values();
        $request->merge(['items' => $rows->all()]);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'stage' => ['required', Rule::enum(QuoteStage::class)],
            'issue_date' => ['required', 'date'],
            'valid_until' => ['required', 'date', 'after_or_equal:issue_date'],
            'notes' => ['nullable', 'string', 'max:20000'],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.amount' => ['required', Money::signedRule()],
            'items.*.taxable' => ['nullable', 'boolean'],
        ], [
            'items.required' => 'Add at least one line item.',
            'items.*.description.required' => 'Every line with an amount needs a description.',
        ]);

        $data['items'] = collect($data['items'])->map(fn ($item) => [
            'id' => isset($item['id']) ? (int) $item['id'] : null,
            'description' => $item['description'],
            'amount' => Money::parse($item['amount']),
            'taxable' => (bool) ($item['taxable'] ?? false),
        ])->all();

        return $data;
    }
}
