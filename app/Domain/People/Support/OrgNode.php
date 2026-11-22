<?php

declare(strict_types=1);

namespace App\Domain\People\Support;

/** D-79: one person on the Hierarchy page. Only what the page shows: name, job title and avatar. */
final readonly class OrgNode
{
    /** @param list<OrgNode> $children */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $position,
        public ?string $avatarUrl,
        public string $initials,
        public array $children = [],
    ) {}

    /** Everyone below this person, at any depth. */
    public function descendants(): int
    {
        return array_reduce($this->children, fn (int $carry, OrgNode $child): int => $carry + 1 + $child->descendants(), 0);
    }
}
