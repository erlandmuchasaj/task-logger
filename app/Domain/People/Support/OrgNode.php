<?php

declare(strict_types=1);

namespace App\Domain\People\Support;

/**
 * D-79, D-89: one person on the Hierarchy chart. Only what a card shows: name, job title, department (and its safe
 * colour) and avatar, plus the depth below the top person.
 */
final readonly class OrgNode
{
    /** @param list<OrgNode> $children */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $position,
        public ?string $avatarUrl,
        public string $initials,
        public ?string $department = null,
        public ?string $color = null,
        public int $depth = 0,
        public array $children = [],
    ) {}

    /** Everyone below this person, at any depth. */
    public function descendants(): int
    {
        return array_reduce($this->children, fn (int $carry, OrgNode $child): int => $carry + 1 + $child->descendants(), 0);
    }
}
