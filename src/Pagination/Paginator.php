<?php

declare(strict_types=1);

namespace Selectwin\Pagination;

/**
 * Auto-pagination over the API's list envelope (`{data, hasMore, ...}`).
 *
 * Iterating yields every item across all pages; {@see first()} returns the first
 * page model, {@see pages()} the page objects, {@see toArray()} collects items.
 *
 * @implements \IteratorAggregate<int, mixed>
 */
final class Paginator implements \IteratorAggregate
{
    /** @param \Closure(int $limit, int $offset): mixed $fetchPage */
    public function __construct(
        private \Closure $fetchPage,
        private int $limit = 20,
        private int $offset = 0,
    ) {}

    /** The first page object (`{data, hasMore, ...}`). */
    public function first(): mixed
    {
        return ($this->fetchPage)($this->limit, $this->offset);
    }

    /** @return \Generator<int, mixed> page objects, one per fetch */
    public function pages(): \Generator
    {
        $offset = $this->offset;
        while (true) {
            $page = ($this->fetchPage)($this->limit, $offset);
            yield $page;
            $items = $this->items($page);
            if (!$this->hasMore($page) || count($items) === 0) {
                return;
            }
            $offset += count($items);
        }
    }

    public function getIterator(): \Generator
    {
        foreach ($this->pages() as $page) {
            foreach ($this->items($page) as $item) {
                yield $item;
            }
        }
    }

    /** Collect items into an array, optionally capped at $max. @return list<mixed> */
    public function toArray(?int $max = null): array
    {
        $out = [];
        foreach ($this as $item) {
            $out[] = $item;
            if ($max !== null && count($out) >= $max) {
                break;
            }
        }
        return $out;
    }

    /** @return array<int, mixed> */
    private function items(mixed $page): array
    {
        if (is_object($page) && method_exists($page, 'getData')) {
            $d = $page->getData();
            return is_array($d) ? $d : [];
        }
        if (is_array($page)) {
            return $page['data'] ?? [];
        }
        return [];
    }

    private function hasMore(mixed $page): bool
    {
        if (is_object($page) && method_exists($page, 'getHasMore')) {
            return (bool) $page->getHasMore();
        }
        if (is_array($page)) {
            return (bool) ($page['hasMore'] ?? false);
        }
        return false;
    }
}
