@php
    use App\Domain\Leave\Enums\ApprovalStage;
    use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
@endphp

<dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
    <div>
        <dt class="text-gray-500 dark:text-gray-400">Employee</dt>
        <dd class="font-medium text-gray-950 dark:text-white">{{ $request->user->getFilamentName() }}</dd>
    </div>

    <div>
        <dt class="text-gray-500 dark:text-gray-400">Type</dt>
        <dd class="font-medium text-gray-950 dark:text-white">{{ $request->leaveType?->label() ?? 'Leave' }}</dd>
    </div>

    <div>
        <dt class="text-gray-500 dark:text-gray-400">Dates</dt>
        <dd class="font-medium text-gray-950 dark:text-white">{{ LeaveRequestResource::period($request) }}</dd>
    </div>

    <div>
        <dt class="text-gray-500 dark:text-gray-400">Days</dt>
        <dd class="font-medium text-gray-950 dark:text-white">{{ $request->days_requested }}</dd>
    </div>

    <div>
        <dt class="text-gray-500 dark:text-gray-400">Status</dt>
        <dd class="mt-0.5">
            <x-filament::badge :color="$request->status->getColor()" :icon="$request->status->getIcon()">{{ $request->status->getLabel() }}</x-filament::badge>
        </dd>
    </div>

    <div class="sm:col-span-2">
        <dt class="text-gray-500 dark:text-gray-400">Approvals</dt>
        <dd class="mt-1 flex flex-col gap-1.5">
            @foreach (ApprovalStage::cases() as $stage)
                @php($decision = $request->stageDecision($stage))
                <div class="flex flex-wrap items-baseline gap-x-2" wire:key="stage-{{ $stage->value }}">
                    <span class="font-medium text-gray-950 dark:text-white">{{ $stage->getLabel() }}:</span>
                    @if ($decision)
                        <span>{{ $decision->decision->getLabel() }}</span>
                        @if ($decision->reviewer)
                            <span class="text-gray-500 dark:text-gray-400">by {{ $decision->reviewer->getFilamentName() }}</span>
                        @endif
                        @if ($decision->note)
                            <span class="text-gray-500 dark:text-gray-400">&ldquo;{{ $decision->note }}&rdquo;</span>
                        @endif
                    @elseif ($stage === ApprovalStage::Manager && $request->user->manager_id === null)
                        <span class="text-gray-500 dark:text-gray-400">n/a</span>
                    @else
                        <span class="text-gray-500 dark:text-gray-400">waiting</span>
                    @endif
                </div>
            @endforeach
        </dd>
    </div>

    @if (filled($request->reason))
        <div class="sm:col-span-2">
            <dt class="text-gray-500 dark:text-gray-400">Reason</dt>
            <dd class="whitespace-pre-line text-gray-950 dark:text-white">{{ $request->reason }}</dd>
        </div>
    @endif

    @if (filled($request->review_note))
        <div class="sm:col-span-2">
            <dt class="text-gray-500 dark:text-gray-400">Decision note</dt>
            <dd class="whitespace-pre-line text-gray-950 dark:text-white">{{ $request->review_note }}</dd>
        </div>
    @endif
</dl>
