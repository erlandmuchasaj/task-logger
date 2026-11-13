<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeaveRequests\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Leave\Actions\CreateDirectDayOff;
use App\Domain\Leave\Actions\RequestLeave;
use App\Domain\Leave\Data\LeaveRequestData;
use App\Domain\Leave\Enums\DayPart;
use App\Domain\Leave\Models\LeaveRequest;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Support\ActionErrors;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

final class ManageLeaveRequests extends ManageRecords
{
    protected static string $resource = LeaveRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('request')->label('Request leave')->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => (bool) auth()->user()?->can('create', LeaveRequest::class))
                ->schema(fn (Schema $schema): Schema => $schema->components(LeaveRequestResource::requestFields())->columns(2))
                ->action(function (array $data, Action $action): void {
                    try {
                        $request = resolve(RequestLeave::class)->handle(auth()->user(), $this->input($data));
                        Notification::make()->title('Leave requested: '.$request->days_requested.' days')->success()->send();
                    } catch (ValidationException $validationException) {
                        ActionErrors::notify($validationException);
                        $action->halt();
                    }
                }),
            Action::make('dayOff')->label('Record a day off')->icon(Heroicon::OutlinedCalendarDays)->color('gray')
                ->visible(fn (): bool => (bool) auth()->user()?->can('createForOthers', LeaveRequest::class))
                ->modalDescription('For someone who did not come to work. It is stored as approved and deducted straight away.')
                ->schema(fn (Schema $schema): Schema => $schema->components([
                    Select::make('user_id')->label('Employee')->required()->searchable()
                        ->options(fn (): array => User::query()->whereNull('anonymized_at')->orderBy('name')->get()
                            ->mapWithKeys(fn (User $user): array => [$user->getKey() => $user->getFilamentName()])->all()),
                    ...LeaveRequestResource::requestFields(note: true),
                    Toggle::make('override_balance')->label('Allow it to take the balance below zero'),
                ])->columns(2))
                ->action(function (array $data, Action $action): void {
                    try {
                        $employee = User::query()->whereKey($data['user_id'])->firstOrFail();
                        resolve(CreateDirectDayOff::class)->handle(auth()->user(), $employee, $this->input($data), (bool) ($data['override_balance'] ?? false));
                        Notification::make()->title('Day off recorded')->success()->send();
                    } catch (ValidationException $validationException) {
                        ActionErrors::notify($validationException);
                        $action->halt();
                    }
                }),
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function input(array $data): LeaveRequestData
    {
        $part = fn (mixed $value): DayPart => $value instanceof DayPart ? $value : DayPart::from((string) $value);

        return new LeaveRequestData(
            (int) $data['leave_type_id'],
            CarbonImmutable::parse((string) $data['start_date']),
            CarbonImmutable::parse((string) $data['end_date']),
            $part($data['start_day_part']),
            $part($data['end_day_part']),
            $data['reason'] ?? null,
        );
    }
}
