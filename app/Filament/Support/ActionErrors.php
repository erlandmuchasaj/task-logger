<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/** Shows a domain action's validation errors on the matching form fields (Filament only shows keys under the form state path). */
final class ActionErrors
{
    /** @param  array<string, string>  $aliases  action field => form field path (without the prefix) */
    public static function report(Component $page, ValidationException $e, string $prefix = 'data.', array $aliases = []): void
    {
        foreach ($e->errors() as $key => $messages) {
            foreach ($messages as $message) {
                $page->addError($prefix.($aliases[$key] ?? $key), $message);
            }
        }

        Notification::make()->title('Could not save')->body((string) collect($e->errors())->flatten()->first())->danger()->send();
    }

    /** For row actions and modals: a notification only. */
    public static function notify(ValidationException $e): void
    {
        Notification::make()->title((string) collect($e->errors())->flatten()->first())->danger()->send();
    }
}
