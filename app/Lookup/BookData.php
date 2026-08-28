<?php
declare(strict_types=1);

namespace App\Lookup;

/**
 * What a metadata source managed to find for one ISBN.
 *
 * Every field is optional. Sources disagree about completeness, and a partial
 * answer is still worth showing - it is confirmed by a human before it is
 * saved, never written blind.
 */
final class BookData
{
    /** @param list<array{name: string, role: string}> $authors */
    /** @param list<string> $tags */
    public function __construct(
        public readonly string $source,
        public readonly ?string $isbn13 = null,
        public readonly ?string $isbn10 = null,
        public readonly ?string $title = null,
        public readonly ?string $subtitle = null,
        public readonly array $authors = [],
        public readonly ?string $publisher = null,
        public readonly ?int $publishedYear = null,
        public readonly ?int $pageCount = null,
        public readonly ?string $language = null,
        public readonly ?string $binding = null,
        public readonly ?float $price = null,
        public readonly string $priceCurrency = 'EUR',
        public readonly array $tags = [],
        public readonly ?string $coverUrl = null,
        public readonly ?string $attribution = null,
    ) {
    }

    /** A result is only useful if it at least names the book. */
    public function isUsable(): bool
    {
        return $this->title !== null && trim($this->title) !== '';
    }

    /**
     * Fill this result's gaps from a later source without overwriting
     * anything already known. The first source in the chain stays
     * authoritative for every field it answered.
     */
    public function mergeMissingFrom(self $other): self
    {
        /** @param mixed $mine @param mixed $theirs */
        $pick = static fn (mixed $mine, mixed $theirs): mixed => $mine ?? $theirs;

        return new self(
            source:        $this->source,
            isbn13:        $pick($this->isbn13, $other->isbn13),
            isbn10:        $pick($this->isbn10, $other->isbn10),
            title:         $pick($this->title, $other->title),
            subtitle:      $pick($this->subtitle, $other->subtitle),
            authors:       $this->authors !== [] ? $this->authors : $other->authors,
            publisher:     $pick($this->publisher, $other->publisher),
            publishedYear: $pick($this->publishedYear, $other->publishedYear),
            pageCount:     $pick($this->pageCount, $other->pageCount),
            language:      $pick($this->language, $other->language),
            binding:       $pick($this->binding, $other->binding),
            price:         $pick($this->price, $other->price),
            priceCurrency: $this->price !== null ? $this->priceCurrency : $other->priceCurrency,
            tags:          $this->tags !== [] ? $this->tags : $other->tags,
            coverUrl:      $pick($this->coverUrl, $other->coverUrl),
            attribution:   $this->coverUrl !== null ? $this->attribution : $other->attribution,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'source'         => $this->source,
            'isbn13'         => $this->isbn13,
            'isbn10'         => $this->isbn10,
            'title'          => $this->title,
            'subtitle'       => $this->subtitle,
            'authors'        => $this->authors,
            'publisher'      => $this->publisher,
            'published_year' => $this->publishedYear,
            'page_count'     => $this->pageCount,
            'language'       => $this->language,
            'binding'        => $this->binding,
            'price'          => $this->price,
            'price_currency' => $this->priceCurrency,
            'tags'           => $this->tags,
            'cover_url'      => $this->coverUrl,
            'attribution'    => $this->attribution,
        ];
    }
}
