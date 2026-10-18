{{-- The reverse charge note on an invoice or quote PDF, with both VAT numbers. --}}
@if ($document->reverse_charge)
    <div style="margin-top: 24px;">
        <div><strong>Reverse charge</strong></div>
        <div>VAT to be accounted for by the customer (Article 196, Council Directive 2006/112/EC).</div>
        @if ($company['tax_id'])<div>Supplier VAT number: {{ $company['tax_id'] }}</div>@endif
        @if ($document->client_tax_id)<div>Customer VAT number: {{ $document->client_tax_id }}</div>@endif
    </div>
@endif
