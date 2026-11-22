<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\People\Enums\OffboardingStatus;
use App\Domain\People\Models\Offboarding;
use App\Domain\People\Notifications\ManagerChanged;
use App\Domain\People\Notifications\ReportsReassignedToYou;
use App\Domain\People\Support\DirectReports;
use App\Domain\People\Support\ManagerChain;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * D-74: before someone is offboarded, all of their active direct reports move to one other manager. Needs
 * offboardings.manage, not employees.update, so HR can finish an offboarding without the superadmin. Each person is
 * updated as a model, so the audit log names the actor and the old and new manager; everybody keeps a manager (D-66).
 * The new manager and each moved person are told (email and in-app).
 */
final class ReassignDirectReports
{
    /**
     * @return int how many people moved
     *
     * @throws ValidationException
     */
    public function handle(User $actor, User $departing, User $newManager): int
    {
        Gate::forUser($actor)->authorize('create', Offboarding::class);

        return resolve(CauserResolver::class)->withCauser($actor, fn (): int => DB::transaction(function () use ($departing, $newManager): int {
            User::query()->whereKey($departing->getKey())->lockForUpdate()->first();
            $newManager = User::query()->whereKey($newManager->getKey())->lockForUpdate()->firstOrFail();

            $this->assertCanTakeOver($departing, $newManager);

            $reports = DirectReports::activeOf($departing)->lockForUpdate()->get();

            foreach ($reports as $report) {
                if (ManagerChain::wouldCycle($report->getKey(), $newManager->getKey())) {
                    throw ValidationException::withMessages(['new_manager_id' => $newManager->getFilamentName().' works under '.$report->getFilamentName().', so they cannot become their manager.']);
                }
            }

            foreach ($reports as $report) {
                $report->update(['manager_id' => $newManager->getKey()]);
            }

            $this->notify($departing, $newManager, $reports);

            return $reports->count();
        }));
    }

    /**
     * The new manager and every moved person are told by email and in the app, at once and after the commit, so a
     * rollback (an offboarding refused after the move) sends nothing. Moved people are active by selection (D-74).
     *
     * @param  Collection<int, User>  $reports
     */
    private function notify(User $departing, User $newManager, Collection $reports): void
    {
        if ($reports->isEmpty()) {
            return;
        }

        $names = array_values($reports->map(fn (User $report): string => $report->getFilamentName())->all());
        $previous = $departing->getFilamentName();
        $current = $newManager->getFilamentName();

        DB::afterCommit(function () use ($newManager, $reports, $names, $previous, $current): void {
            $newManager->notify(new ReportsReassignedToYou($names));

            foreach ($reports as $report) {
                $report->notify(new ManagerChanged($current, $previous));
            }
        });
    }

    /** @throws ValidationException */
    private function assertCanTakeOver(User $departing, User $newManager): void
    {
        if ($newManager->is($departing)) {
            throw ValidationException::withMessages(['new_manager_id' => 'Choose someone other than the person who is leaving.']);
        }

        if ($newManager->isBanned() || $newManager->anonymized_at !== null) {
            throw ValidationException::withMessages(['new_manager_id' => 'This person is deactivated and cannot manage a team.']);
        }

        $leaving = Offboarding::query()->where('user_id', $newManager->getKey())->where('status', '!=', OffboardingStatus::Cancelled)->exists();

        if ($leaving) {
            throw ValidationException::withMessages(['new_manager_id' => 'This person is being offboarded too. Choose someone who is staying.']);
        }
    }
}
