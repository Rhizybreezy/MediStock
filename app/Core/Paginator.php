<?php
declare(strict_types=1);

namespace App\Core;

/** Result page for server-side tables. */
final class Paginator
{
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }

    public function from(): int
    {
        return $this->total === 0 ? 0 : ($this->page - 1) * $this->perPage + 1;
    }

    public function to(): int
    {
        return min($this->total, $this->page * $this->perPage);
    }

    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'meta'  => ['total' => $this->total, 'page' => $this->page, 'per_page' => $this->perPage, 'last_page' => $this->lastPage()],
        ];
    }
}
