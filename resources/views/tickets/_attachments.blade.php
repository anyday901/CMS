<label class="block">Attachments (optional)
    <input type="file" name="attachments[]" multiple accept="{{ collect(App\Tickets\TicketDesk::EXTENSIONS)->map(fn ($e) => '.'.$e)->join(',') }}" class="mt-1 block w-full text-sm">
    <span class="text-ink-500">Up to {{ App\Tickets\TicketDesk::MAX_FILES }} files, {{ App\Tickets\TicketDesk::MAX_KB / 1024 }} MB each: images, PDFs, text, office documents or zip.</span>
</label>
