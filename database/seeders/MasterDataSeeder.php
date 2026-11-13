<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\MasterData\Enums\PaymentPlanType;
use App\Domain\MasterData\Models\Label;
use App\Domain\MasterData\Models\LeaveType;
use App\Domain\MasterData\Models\PaymentPlan;
use Illuminate\Database\Seeder;

/** Seeds only values the spec names (SPEC 5.1). Countries, departments and technologies have their own seeders; leave policies are entered by the superadmin. */
class MasterDataSeeder extends Seeder
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

        foreach (['Bug', 'Feature', 'Design', 'Testing', 'Refactoring', 'Enhancement', 'Documentation'] as $label) {
            Label::firstOrCreate(['name' => $label]);
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
