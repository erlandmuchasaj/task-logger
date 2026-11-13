<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Identity\Permissions;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Support\Days;
use App\Domain\Leave\Support\LeaveBalance;
use App\Domain\MasterData\Models\LeaveType;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** FR-34 My Leave: the balance per leave type (granted, used, pending, available) and the shortcut to requests. */
final class MyLeave extends Page
{
    protected string $view = 'filament.pages.my-leave';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Leave';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'My Leave';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(Permissions::LEAVE_REQUESTS_VIEW_OWN);
    }

    /** @return list<array{type: string, balance: string, pending: string, available: string}> */
    public function balances(): array
    {
        $user = auth()->user();

        return array_values(LeaveType::query()->where('affects_balance', true)->where('is_active', true)->orderBy('name')->get()
            ->map(fn (LeaveType $type): array => [
                'type' => ucfirst(str_replace('_', ' ', $type->name)),
                'balance' => Days::format(LeaveBalance::balance($user, (int) $type->getKey())),
                'pending' => Days::format(LeaveBalance::pending($user, (int) $type->getKey())),
                'available' => Days::format(LeaveBalance::available($user, (int) $type->getKey())),
            ])->all());
    }

    public function upcoming(): int
    {
        return LeaveRequest::query()->where('user_id', auth()->id())->whereIn('status', ['pending', 'approved'])->count();
    }
}
