<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\People\Support\PersonDetails;
use App\Domain\People\Support\PersonDetailsQuery;
use App\Filament\Resources\Employees\EmployeeResource;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * D-89: the read-only profile behind a name on the Hierarchy chart. Open to everyone who holds hierarchy.view, kept out
 * of the navigation. What each viewer may read is decided in `PersonDetailsQuery`; a person who is not active has no
 * profile (404). Only the id is kept in the component; the details are read again on every request.
 */
final class ViewPerson extends Page
{
    #[Locked]
    public int $userId = 0;

    protected string $view = 'filament.pages.view-person';

    protected static bool $shouldRegisterNavigation = false;

    public static function getRoutePath(Panel $panel): string
    {
        return '/hierarchy/{user}';
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can(Permissions::HIERARCHY_VIEW);
    }

    public function mount(int|string $user): void
    {
        abort_unless(self::canAccess(), 403);

        $this->userId = (int) $user;

        $this->details();
    }

    #[Computed]
    public function details(): PersonDetails
    {
        abort_unless(self::canAccess(), 403);

        $viewer = auth()->user();
        abort_unless($viewer instanceof User, 403);

        return PersonDetailsQuery::find($viewer, $this->userId) ?? abort(404);
    }

    public function getTitle(): string
    {
        return $this->details()->name;
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Back to hierarchy')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(Hierarchy::getUrl()),
            Action::make('openInEmployees')
                ->label('Open in Employees')
                ->icon(Heroicon::OutlinedUserCircle)
                ->color('gray')
                ->visible(fn (): bool => $this->employeesUrl() !== null)
                ->url(fn (): ?string => $this->employeesUrl()),
        ];
    }

    /** The Employees screen the viewer may actually open for this person: the edit page, else the list, else none. */
    private function employeesUrl(): ?string
    {
        $viewer = auth()->user();
        $person = User::query()->find($this->userId);

        if (! $viewer instanceof User || ! $person instanceof User) {
            return null;
        }

        if ($viewer->can('update', $person)) {
            return EmployeeResource::getUrl('edit', ['record' => $person]);
        }

        return $viewer->can('viewAny', User::class) ? EmployeeResource::getUrl() : null;
    }
}
