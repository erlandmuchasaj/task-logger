<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\MasterData\Models\LeaveType;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The person's leave requests on their employee page (read-only; decisions stay on the Leave Requests
 * screen). Visible with leave_requests.view_any, or to the person's direct manager (as LeaveRequestPolicy).
 */
final class LeaveRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'leaveRequests';

    protected static ?string $title = 'Leave requests';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $viewer = auth()->user();

        return $viewer !== null && ($viewer->can(Permissions::LEAVE_REQUESTS_VIEW_ANY)
            || ($viewer->can(Permissions::LEAVE_REQUESTS_APPROVE_DIRECT_REPORTS) && $ownerRecord instanceof User && $ownerRecord->manager_id === $viewer->getKey()));
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Leave requests')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('leaveType'))
            ->columns([
                TextColumn::make('leaveType.name')->label('Type')->badge()->color('info')
                    ->formatStateUsing(fn (string $state): string => Str::headline($state)),
                TextColumn::make('status')->badge(),
                TextColumn::make('start_date')->label('Start date')->date()->sortable(),
                TextColumn::make('end_date')->label('End date')->date()->sortable(),
                TextColumn::make('days_requested')->label('Days requested')->alignEnd()->sortable(),
                TextColumn::make('reason')->limit(40)->toggleable(),
                TextColumn::make('review_note')->label('Decision note')->limit(40)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(LeaveStatus::class),
                SelectFilter::make('leave_type')->label('Type')->relationship('leaveType', 'name')
                    ->getOptionLabelFromRecordUsing(fn (LeaveType $record): string => $record->label()),
            ])
            ->defaultSort('start_date', 'desc')
            ->emptyStateHeading('No leave requests yet');
    }
}
