<label class="block">Title
    <input name="title" value="{{ old('title', $reply->title) }}" required maxlength="255" placeholder="Password reset steps" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
</label>
<label class="block">Reply
    <textarea name="body" rows="8" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ old('body', $reply->body) }}</textarea>
    <span class="text-ink-500">You can use {{ collect(App\Models\CannedReply::PLACEHOLDERS)->map(fn ($name) => '{'.$name.'}')->join(', ') }}.</span>
</label>
