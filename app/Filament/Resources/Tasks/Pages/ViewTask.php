<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Pages;

use App\Domain\Work\Enums\TaskStatus;
use App\Domain\Work\Models\Log;
use App\Domain\Work\Models\Task;
use App\Filament\Resources\Logs\LogResource;
use App\Filament\Resources\Tasks\TaskResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewTask extends ViewRecord
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // D-55: the assignee logs hours from the task page; the log form opens with this task chosen.
            Action::make('logHours')->label('Log hours')->icon(Heroicon::OutlinedClock)
                ->visible(fn (Task $record): bool => $record->assignee_id === auth()->id()
                    && ! in_array($record->status, [TaskStatus::Completed, TaskStatus::Cancelled], true)
                    && (bool) auth()->user()?->can('create', Log::class))
                ->url(fn (Task $record): string => LogResource::getUrl('create', ['task' => $record->getKey()])),
            EditAction::make(),
        ];
    }
}
