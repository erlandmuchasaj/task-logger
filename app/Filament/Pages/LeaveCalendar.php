<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Identity\Permissions;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use Filament\Pages\Page;

/**
 * FR-34: the Leave Calendar is now the Calendar tab of the Leave Requests page. This page only keeps the old
 * address (/app/leave-calendar) working, for bookmarks, by sending people to that tab. It has no navigation item.
 */
final class LeaveCalendar extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Leave Calendar';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(Permissions::BANK_HOLIDAYS_VIEW);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);

        $this->redirect(LeaveRequestResource::getUrl('index', ['tab' => 'calendar']));
    }
}
