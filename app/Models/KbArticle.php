<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A knowledge base article, written in Markdown. */
class KbArticle extends Model
{
    /** Words too common to say what an article is about. */
    private const COMMON_WORDS = [
        'the', 'and', 'for', 'are', 'but', 'not', 'you', 'your', 'can', 'cant', 'has', 'have', 'had', 'was', 'were', 'with', 'this', 'that',
        'from', 'they', 'them', 'there', 'what', 'when', 'where', 'which', 'who', 'why', 'how', 'all', 'any', 'our', 'out', 'get', 'got',
        'just', 'into', 'its', 'also', 'been', 'does', 'did', 'doesnt', 'dont', 'isnt', 'wont', 'will', 'would', 'could', 'should', 'please',
        'thanks', 'thank', 'hello', 'help', 'need', 'want', 'like', 'some', 'still', 'since', 'today', 'now', 'one', 'able', 'about', 'after',
    ];

    protected $fillable = ['kb_category_id', 'title', 'body', 'tags', 'published', 'sort_order'];

    protected function casts(): array
    {
        return ['published' => 'boolean', 'sort_order' => 'integer', 'views' => 'integer', 'helpful' => 'integer', 'not_helpful' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(KbCategory::class, 'kb_category_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('title');
    }

    /** @param  Builder<self>  $query Published, in a category clients can see. */
    public function scopeVisible(Builder $query): void
    {
        $query->where('published', true)->whereHas('category', fn ($category) => $category->visible());
    }

    /** @param  Builder<self>  $query Every word must be in the title, text or tags. */
    public function scopeSearch(Builder $query, string $terms): void
    {
        foreach (array_slice(preg_split('/\s+/', trim($terms), -1, PREG_SPLIT_NO_EMPTY), 0, 8) as $word) {
            $like = '%'.addcslashes($word, '\\%_').'%';
            $query->where(fn ($match) => $match->where('title', 'like', $like)->orWhere('body', 'like', $like)->orWhere('tags', 'like', $like));
        }
    }

    /**
     * Visible articles that best match what a client is typing, such as a
     * new ticket's subject and message. Unlike search, an article needs only
     * some of the words; words in the title count most, then tags, then text.
     *
     * @return Collection<int, self>
     */
    public static function suggest(string $text, int $limit = 5): Collection
    {
        $words = collect(preg_split('/[^\p{L}\p{N}]+/u', Str::lower(Str::limit($text, 500, '')), -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn (string $word) => mb_strlen($word) >= 3 && ! in_array($word, self::COMMON_WORDS, true))
            ->unique()
            ->take(12)
            ->values();

        if ($words->isEmpty()) {
            return new Collection;
        }

        $candidates = self::visible()
            ->where(function (Builder $query) use ($words) {
                foreach ($words as $word) {
                    $like = '%'.addcslashes($word, '\\%_').'%';
                    $query->orWhere('title', 'like', $like)->orWhere('tags', 'like', $like)->orWhere('body', 'like', $like);
                }
            })
            ->orderByDesc('views')
            ->limit(100)
            ->get();

        return $candidates
            ->map(function (self $article) use ($words) {
                $title = Str::lower($article->title);
                $tags = Str::lower((string) $article->tags);
                $body = Str::lower($article->body);
                $article->score = $words->sum(fn (string $word) => match (true) {
                    str_contains($title, $word) => 3,
                    str_contains($tags, $word) => 2,
                    str_contains($body, $word) => 1,
                    default => 0,
                });

                return $article;
            })
            ->filter(fn (self $article) => $article->score > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->values();
    }

    public function isVisible(): bool
    {
        return $this->published && $this->category->isVisible();
    }

    /** @return list<string> */
    public function tagList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->tags))));
    }
}
