@php
    use App\Domain\Reports\Data\EstimateLine;

    $result = $this->result();
    $report = $result->projects;
    $totals = ['estimated_hundredths' => $result->estimatedHundredths, 'logged_minutes' => $result->loggedMinutes, 'values' => $result->values];
    $seesValue = auth()->user()?->can(\App\Domain\Identity\Permissions::CLIENTS_VIEW_RATES);
    $loggedTotal = new EstimateLine(0, 'Total', $totals['estimated_hundredths'], $totals['logged_minutes'], null);
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <form>{{ $this->form }}</form>
    </x-filament::section>

    <x-filament::section heading="Totals" description="Over every project the filters match. Dates limit the logs counted; estimates are always the full estimate.">
        <div class="grid gap-4 sm:grid-cols-3">
            <div><div class="text-sm text-gray-500">Estimated</div><div class="text-2xl font-semibold">{{ EstimateLine::hours($totals['estimated_hundredths']) }} h</div></div>
            <div><div class="text-sm text-gray-500">Logged</div><div class="text-2xl font-semibold">{{ EstimateLine::hours($loggedTotal->loggedHundredths()) }} h</div></div>
            @if ($seesValue)
                <div>
                    <div class="text-sm text-gray-500">Logged value</div>
                    @forelse ($totals['values'] as $money)
                        <div class="text-2xl font-semibold">{{ $money->format() }}</div>
                    @empty
                        <div class="text-2xl font-semibold">-</div>
                    @endforelse
                </div>
            @endif
        </div>
    </x-filament::section>

    @forelse ($report as $project)
        <x-filament::section wire:key="project-{{ $project->projectId }}" :heading="$project->project" :description="$project->customer.' · '.$project->status">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500 dark:border-white/10">
                            <th class="py-2 pe-4">Technology</th>
                            <th class="py-2 pe-4 text-end">Estimated (h)</th>
                            <th class="py-2 pe-4 text-end">Logged (h)</th>
                            <th class="py-2 pe-4 text-end">Difference (h)</th>
                            <th class="py-2 pe-4 text-end">Used</th>
                            @if ($seesValue)<th class="py-2 text-end">Logged value</th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ([...$project->lines, $project->total()] as $line)
                            @php($isTotal = $line->technologyId === 0)
                            <tr class="{{ $isTotal ? 'border-t border-gray-200 font-semibold dark:border-white/10' : '' }}" wire:key="line-{{ $project->projectId }}-{{ $line->technologyId }}">
                                <td class="py-1.5 pe-4">{{ $line->technology }}</td>
                                <td class="py-1.5 pe-4 text-end">{{ EstimateLine::hours($line->estimatedHundredths) }}</td>
                                <td class="py-1.5 pe-4 text-end">{{ EstimateLine::hours($line->loggedHundredths()) }}</td>
                                <td class="py-1.5 pe-4 text-end {{ $line->varianceHundredths() > 0 ? 'text-danger-600' : '' }}">{{ ($line->varianceHundredths() > 0 ? '+' : '').EstimateLine::hours($line->varianceHundredths()) }}</td>
                                <td class="py-1.5 pe-4 text-end">{{ $line->percentUsed() === null ? '-' : $line->percentUsed().'%' }}</td>
                                @if ($seesValue)<td class="py-1.5 text-end">{{ $line->value?->format() ?? '-' }}</td>@endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @empty
        <x-filament::section><p class="text-sm text-gray-500">No projects match.</p></x-filament::section>
    @endforelse

    <div class="flex items-center justify-between">
        <x-filament::button color="gray" size="sm" wire:click="previous" :disabled="$report->currentPage() <= 1">Previous</x-filament::button>
        <span class="text-sm text-gray-500">Page {{ $report->currentPage() }} of {{ max($report->lastPage(), 1) }} · {{ $report->total() }} project(s)</span>
        <x-filament::button color="gray" size="sm" wire:click="next" :disabled="! $report->hasMorePages()">Next</x-filament::button>
    </div>
</x-filament-panels::page>
