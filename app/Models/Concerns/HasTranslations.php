<?php

namespace App\Models\Concerns;

use App\Models\ContentTranslation;
use App\Support\Language;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Content with translations into the other languages under Settings.
 *
 * Public pages load the translation into the visitor's language with
 * translated() or loadTranslation(); the model's text attributes then read
 * from it where it has them. Anywhere else, such as the admin area, they're
 * the main language as written.
 */
trait HasTranslations
{
    public static function bootHasTranslations(): void
    {
        static::deleting(fn (self $model) => $model->translations()->delete());
    }

    public function translations(): MorphMany
    {
        return $this->morphMany(ContentTranslation::class, 'translatable')->orderBy('locale');
    }

    /** The translation into the visitor's language. */
    public function translation(): MorphOne
    {
        return $this->morphOne(ContentTranslation::class, 'translatable')->where('locale', Language::current());
    }

    /** @param  Builder<self>  $query  With the translation into the visitor's language, when they aren't reading the main one. */
    public function scopeTranslated(Builder $query): void
    {
        if (Language::translating()) {
            $query->with('translation');
        }
    }

    public function loadTranslation(): static
    {
        return Language::translating() ? $this->load('translation') : $this;
    }

    /** The language the text is shown in, for the page's lang attribute. */
    public function shownLanguage(): string
    {
        return $this->loadedTranslation()?->locale ?? Language::main();
    }

    /** An attribute from the loaded translation, or the main language's value when there's none. */
    protected function fromTranslation(string $attribute, mixed $value): mixed
    {
        return $this->loadedTranslation()?->getAttribute($attribute) ?? $value;
    }

    private function loadedTranslation(): ?ContentTranslation
    {
        return $this->relationLoaded('translation') ? $this->getRelation('translation') : null;
    }
}
