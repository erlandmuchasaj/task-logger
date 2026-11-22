<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Identity\Permissions;
use App\Domain\People\Support\OrgNode;
use App\Domain\People\Support\OrgTree;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * D-79: the company reporting tree of everyone who is active, as an expandable list. It shows name, avatar and job
 * title only (D-68 default: no role, no contact data). Every role holds hierarchy.view through the employee baseline.
 */
final class Hierarchy extends Page
{
    protected string $view = 'filament.pages.hierarchy';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShare;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'Hierarchy';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(Permissions::HIERARCHY_VIEW);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    /** @return list<OrgNode> */
    public function roots(): array
    {
        abort_unless(self::canAccess(), 403);

        return OrgTree::roots();
    }
}
