<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\People\Support\ThisWeek;
use App\Domain\Work\Support\MyWorkSummary;
use Filament\Widgets\Widget;

/** D-78: who joins and leaves this week, and the tasks given to the viewer this week. */
final class ThisWeekPanel extends Widget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.this-week';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->canAny([Permissions::HIERARCHY_VIEW, Permissions::TASKS_VIEW_OWN]);
    }

    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $people = $user->can(Permissions::HIERARCHY_VIEW);

        return [
            'showPeople' => $people,
            'joiners' => $people ? ThisWeek::joiners($user) : [],
            'leavers' => $people ? ThisWeek::leavers($user) : [],
            'showTasks' => $user->can(Permissions::TASKS_VIEW_OWN),
            'tasks' => $user->can(Permissions::TASKS_VIEW_OWN) ? MyWorkSummary::assignedThisWeek($user) : collect(),
        ];
    }
}
