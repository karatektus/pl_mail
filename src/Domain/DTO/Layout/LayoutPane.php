<?php

declare(strict_types=1);

namespace App\Domain\DTO\Layout;

use App\Domain\Enum\Layout\PaneKind;

/** A leaf of the layout tree: one pane, named by what it shows. */
final readonly class LayoutPane extends LayoutNode
{
    public function __construct(
        public PaneKind $kind,
    ) {
    }

    /** @param array<mixed> $data */
    public static function parse(array $data): ?self
    {
        $kind = PaneKind::tryFrom(is_string($data['kind'] ?? null) ? $data['kind'] : '');

        return null === $kind ? null : new self($kind);
    }

    public function toArray(): array
    {
        return ['t' => 'pane', 'kind' => $this->kind->value];
    }

    public function kinds(): array
    {
        return [$this->kind];
    }

    public function hasSplit(string $id): bool
    {
        return false;
    }
}
