<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\MasterData\Enums\PaymentPlanType;
use App\Domain\MasterData\Models\Label;
use App\Domain\MasterData\Models\LeaveType;
use App\Domain\MasterData\Models\PaymentPlan;
use Illuminate\Database\Seeder;

/** Seeds only values the spec names (SPEC 5.1). Countries, departments and technologies have their own seeders; leave policies are entered by the superadmin. */
final class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $leaveTypes = [
            'annual_leave' => true,
            'sick_leave' => true,
            'personal_leave' => false,
            'unpaid_leave' => false,
            'parental_leave' => false,
        ];

        foreach ($leaveTypes as $name => $affectsBalance) {
            LeaveType::firstOrCreate(['name' => $name], ['affects_balance' => $affectsBalance, 'is_active' => true]);
        }

        /** Every label gets its own color; no two share one. */
        $labels = [
            'Bug' => '#ef4444',
            'Feature' => '#22c55e',
            'Design' => '#ec4899',
            'Testing' => '#f59e0b',
            'Refactoring' => '#8b5cf6',
            'Enhancement' => '#3b82f6',
            'Documentation' => '#14b8a6',
        ];

        foreach ($labels as $name => $color) {
            $label = Label::firstOrCreate(['name' => $name], ['color' => $color]);

            if ($label->color === null) {
                $label->update(['color' => $color]);
            }
        }

        $plans = [
            ['30 days after invoice', PaymentPlanType::DaysAfterInvoice, 30],
            ['15 days after invoice', PaymentPlanType::DaysAfterInvoice, 15],
            ['End of the month', PaymentPlanType::EndOfMonth, null],
            ['Prepayment', PaymentPlanType::Prepayment, null],
        ];

        foreach ($plans as [$name, $type, $days]) {
            PaymentPlan::firstOrCreate(['name' => $name], ['type' => $type, 'days' => $days]);
        }
    }
}
