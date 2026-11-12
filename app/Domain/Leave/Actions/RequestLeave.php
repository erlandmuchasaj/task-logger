<?php

declare(strict_types=1);

namespace App\Domain\Leave\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Leave\Data\LeaveRequestData;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Support\Days;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/** W-05 step 1: a person asks for leave for themselves. The server computes the days (BR-16). */
class RequestLeave
{
    use ValidatesLeave;

    public function handle(User $actor, LeaveRequestData $data): LeaveRequest
    {
        Gate::forUser($actor)->authorize('create', LeaveRequest::class);

        $this->prepare($actor, $data);

        return app(CauserResolver::class)->withCauser($actor, fn (): LeaveRequest => DB::transaction(function () use ($actor, $data): LeaveRequest {
            $this->lockEmployee($actor);

            ['span' => $span, 'tenths' => $tenths, 'type' => $type] = $this->prepare($actor, $data);
            $this->assertNoOverlap($actor, $span);
            $this->assertBalance($actor, $type, $tenths);

            return LeaveRequest::create([
                'user_id' => $actor->getKey(),
                'created_by' => $actor->getKey(),
                'leave_type_id' => $type->getKey(),
                'start_date' => $span->start->toDateString(),
                'end_date' => $span->end->toDateString(),
                'start_day_part' => $span->startPart,
                'end_day_part' => $span->endPart,
                'days_requested' => Days::format($tenths),
                'reason' => $data->reason,
                'status' => LeaveStatus::Pending,
            ]);
        }));
    }
}
