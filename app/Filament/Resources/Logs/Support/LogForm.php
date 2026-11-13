<?php

declare(strict_types=1);

namespace App\Filament\Resources\Logs\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Work\Data\LogData;
use App\Domain\Work\Enums\LogType;
use App\Domain\Work\Models\Log;
use App\Domain\Work\Support\LogChoices;
use App\Domain\Work\Support\WorkCalendar;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The log form, shared by the Logs screens and My Day. The checkbox swaps the choices (BR-03): off lists
 * the person's own tasks, on lists only projects that allow direct logging and where they are a member.
 */
final class LogForm
{
    /** @return array<int, mixed> */
    public static function components(User $owner, ?Log $record = null): array
    {
        return [
            DatePicker::make('date')->required()
                ->maxDate(fn (): CarbonImmutable => WorkCalendar::today($owner))
                ->default(fn (): CarbonImmutable => WorkCalendar::today($owner))
                ->helperText('Back-dating is allowed until the month is locked.'),
            Toggle::make('direct')->label('Log directly to a project (no task)')->live()
                ->afterStateUpdated(function (callable $set): void {
                    $set('task_id', null);
                    $set('project_id', null);
                    $set('technology_id', null);
                }),
            Select::make('task_id')->label('Task')
                ->options(fn (): array => LogChoices::taskOptions($owner, $record?->task_id))
                ->searchable()->required(fn (callable $get): bool => ! $get('direct'))
                ->visible(fn (callable $get): bool => ! $get('direct'))
                ->helperText('Your open tasks. Pinned tasks come first.'),
            Select::make('project_id')->label('Project')
                ->options(fn (): array => LogChoices::directProjectOptions($owner, $record?->task_id === null ? $record?->project_id : null))
                ->searchable()->live()->required(fn (callable $get): bool => (bool) $get('direct'))
                ->visible(fn (callable $get): bool => (bool) $get('direct'))
                ->afterStateUpdated(fn (callable $set) => $set('technology_id', null))
                ->helperText('Projects that allow direct logging and where you are a member.'),
            Select::make('technology_id')->label('Technology')
                ->options(fn (callable $get): array => LogChoices::projectTechnologyOptions(filled($get('project_id')) ? (int) $get('project_id') : null))
                ->searchable()->required(fn (callable $get): bool => (bool) $get('direct'))
                ->visible(fn (callable $get): bool => (bool) $get('direct')),
            Section::make('Time')->columns(3)->schema([
                TextInput::make('hours')->numeric()->integer()->minValue(0)->maxValue(24)->default(0)->required(),
                TextInput::make('minutes')->numeric()->integer()->minValue(0)->maxValue(59)->default(0)->required(),
                Select::make('log_type')->label('Type')->options(LogType::class)->default(LogType::NormalHours)->required(),
            ]),
            Textarea::make('description')->rows(2),
        ];
    }

    public static function configure(Schema $schema, User $owner, ?Log $record = null): Schema
    {
        return $schema->components(self::components($owner, $record));
    }

    /** @return array<string, mixed> */
    public static function fill(Log $log): array
    {
        return [
            'date' => $log->date->toDateString(),
            'direct' => $log->isDirect(),
            'task_id' => $log->task_id,
            'project_id' => $log->isDirect() ? $log->project_id : null,
            'technology_id' => $log->isDirect() ? $log->technology_id : null,
            'hours' => $log->hours(),
            'minutes' => $log->minutes(),
            'log_type' => $log->log_type->value,
            'description' => $log->description,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function data(array $data): LogData
    {
        $direct = (bool) ($data['direct'] ?? false);
        $type = $data['log_type'] ?? LogType::NormalHours;

        return new LogData(
            date: CarbonImmutable::parse((string) $data['date']),
            minutes: LogData::minutesFrom((int) ($data['hours'] ?? 0), (int) ($data['minutes'] ?? 0)),
            type: $type instanceof LogType ? $type : LogType::from((string) $type),
            description: $data['description'] ?? null,
            taskId: ! $direct && filled($data['task_id'] ?? null) ? (int) $data['task_id'] : null,
            projectId: $direct && filled($data['project_id'] ?? null) ? (int) $data['project_id'] : null,
            technologyId: $direct && filled($data['technology_id'] ?? null) ? (int) $data['technology_id'] : null,
        );
    }
}
