<?php

declare(strict_types=1);

namespace App\Filament\Resources\Performances\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\People\Actions\CreatePerformanceReview;
use App\Domain\People\Data\PerformanceData;
use App\Domain\People\Models\Performance;
use App\Filament\Resources\Performances\PerformanceResource;
use App\Filament\Support\ActionErrors;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class ManagePerformances extends ManageRecords
{
    protected static string $resource = PerformanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('review')->label('New review')->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => (bool) auth()->user()?->can('create', Performance::class))
                ->schema([
                    Select::make('user_id')->label('Employee')->required()->searchable()
                        ->options(fn (): array => User::query()->whereNull('anonymized_at')->whereNull('banned_at')->orderBy('name')->get()
                            ->mapWithKeys(fn (User $user): array => [$user->getKey() => $user->getFilamentName()])->all()),
                    TextInput::make('score')->numeric()->integer()->minValue(1)->maxValue(10)->required()->helperText('1 to 10'),
                    DatePicker::make('review_date')->required()->native(false)->default(now()),
                    Textarea::make('notes')->rows(4),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        app(CreatePerformanceReview::class)->handle(
                            auth()->user(),
                            User::query()->whereKey($data['user_id'])->firstOrFail(),
                            new PerformanceData((int) $data['score'], CarbonImmutable::parse((string) $data['review_date']), $data['notes'] ?? null),
                        );
                        Notification::make()->title('Review saved')->success()->send();
                    } catch (ValidationException $e) {
                        ActionErrors::notify($e);
                        $action->halt();
                    }
                }),
        ];
    }
}
