<?php

declare(strict_types=1);

namespace Selectwin\Test;

use PHPUnit\Framework\TestCase;
use Selectwin\Pagination\Paginator;

/** Minimal stand-in for a generated list page model. */
final class FakePage
{
    public function __construct(private array $data, private bool $hasMore) {}
    public function getData(): array { return $this->data; }
    public function getHasMore(): bool { return $this->hasMore; }
}

final class PaginatorTest extends TestCase
{
    /** @return FakePage[] */
    private function pages(): array
    {
        return [new FakePage([1, 2], true), new FakePage([3, 4], true), new FakePage([5], false)];
    }

    public function testFirstReturnsFirstPage(): void
    {
        $pages = $this->pages();
        $pager = new Paginator(fn (int $l, int $o) => $pages[0], 2);
        $this->assertSame([1, 2], $pager->first()->getData());
    }

    public function testIteratesEveryItemAndAdvancesOffset(): void
    {
        $pages = $this->pages();
        $seen = [];
        $pager = new Paginator(function (int $l, int $o) use ($pages, &$seen) {
            $seen[] = $o;
            return $pages[count($seen) - 1];
        }, 2);
        $this->assertSame([1, 2, 3, 4, 5], iterator_to_array($pager, false));
        $this->assertSame([0, 2, 4], $seen);
    }

    public function testToArrayHonoursCap(): void
    {
        $pages = $this->pages();
        $i = 0;
        $pager = new Paginator(function (int $l, int $o) use ($pages, &$i) {
            return $pages[$i++];
        }, 2);
        $this->assertSame([1, 2, 3], $pager->toArray(3));
    }

    public function testStopsOnEmptyPageEvenIfHasMore(): void
    {
        $pager = new Paginator(fn (int $l, int $o) => new FakePage([], true), 2);
        $this->assertSame([], iterator_to_array($pager, false));
    }
}
