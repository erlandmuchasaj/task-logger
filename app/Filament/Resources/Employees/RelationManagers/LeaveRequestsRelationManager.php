<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\MasterData\Models\LeaveType;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use Filament\Actions\ActionGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The person's leave requests on their employee page. The list is read-only; the decision buttons are the
 * Leave Requests screen's own row actions (approve, reject, cancel, back at work earlier), so each one shows
 * only to a viewer the LeaveRequestPolicy lets act and goes through DecideLeave, CancelLeave or ReturnFromLeave.
 * Visible with leave_requests.view_any, or to the person's direct manager (as LeaveRequestPolicy).
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'leaveType', 'approvals']))
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
            ->recordActions([ActionGroup::make(LeaveRequestResource::recordActions())])
            ->defaultSort('start_date', 'desc')
            ->emptyStateHeading('No leave requests yet');
    }
}
