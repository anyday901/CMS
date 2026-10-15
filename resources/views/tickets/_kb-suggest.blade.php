{{-- Suggests knowledge base articles from #ticket-subject and #ticket-body into #kb-suggestions, using $suggestUrl. --}}
<script>
    // Suggest knowledge base articles from the subject and message as the client types.
    (() => {
        const subject = document.getElementById('ticket-subject');
        const body = document.getElementById('ticket-body');
        const box = document.getElementById('kb-suggestions');
        const list = box.querySelector('ul');
        let timer;
        let last = '';

        const show = (articles) => {
            list.replaceChildren(...articles.map((article) => {
                const item = document.createElement('li');
                const link = document.createElement('a');
                link.href = article.url;
                link.target = '_blank';
                link.rel = 'noopener';
                link.className = 'font-medium text-brand-700 hover:underline';
                link.textContent = article.title;
                const excerpt = document.createElement('div');
                excerpt.className = 'text-ink-600';
                excerpt.textContent = article.excerpt;
                item.append(link, excerpt);
                return item;
            }));
            box.classList.toggle('hidden', articles.length === 0);
        };

        const lookUp = async () => {
            const query = new URLSearchParams({ subject: subject.value.trim(), body: body.value.trim().slice(0, 300) });
            if (query.toString() === last || subject.value.trim().length + body.value.trim().length < 4) {
                return;
            }
            last = query.toString();
            try {
                const response = await fetch(@js($suggestUrl) + '?' + query, { headers: { Accept: 'application/json' } });
                if (response.ok) {
                    show((await response.json()).articles);
                }
            } catch {
                // Suggestions are a nice extra; the form works without them.
            }
        };

        const later = () => {
            clearTimeout(timer);
            timer = setTimeout(lookUp, 500);
        };
        subject.addEventListener('input', later);
        body.addEventListener('input', later);
        lookUp();
    })();
</script>
