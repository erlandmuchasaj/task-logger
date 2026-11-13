<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Exports\Actions\RequestExport;
use App\Domain\Exports\Enums\ExportType;
use App\Domain\Identity\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/** The "Export" button on a list screen (BR-33). It queues the export; the file appears under Exports. */
final class ExportAction
{
    public static function make(ExportType $type): Action
    {
        $action = Action::make('export')->label('Export')->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn (): bool => auth()->user() instanceof User && $type->exporter()->canExport(auth()->user()));

        if ($type === ExportType::Logs) {
            $action->schema([
                DatePicker::make('date_from')->label('From'),
                DatePicker::make('date_to')->label('To')->afterOrEqual('date_from'),
            ])->modalDescription('Leave the dates empty to export every log. Month close: export first, then lock the month.');
        } else {
            $action->requiresConfirmation();
        }

        return $action->action(function (array $data) use ($type): void {
            $filters = array_map(fn (mixed $v): ?string => $v === null ? null : (string) $v, $data);
            app(RequestExport::class)->handle(auth()->user(), $type, $filters);

            Notification::make()->title('Export queued')->body('It will appear under Work > Exports when it is ready.')->success()->send();
        });
    }
}
