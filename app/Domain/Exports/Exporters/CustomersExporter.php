<?php

declare(strict_types=1);

namespace App\Domain\Exports\Exporters;

use App\Domain\Clients\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class CustomersExporter implements Exporter
{
    public function canExport(User $user): bool
    {
        return $user->can(Permissions::CLIENTS_EXPORT) && $user->can(Permissions::CLIENTS_VIEW);
    }

    public function columns(User $user): array
    {
        $columns = [
            'company' => 'Company', 'contact' => 'Contact', 'email' => 'Email', 'phone' => 'Phone', 'country' => 'Country',
            'priority' => 'Priority', 'status' => 'Status',
        ];

        // D-05: the payment plan and currency are commercial data.
        if ($user->can(Permissions::CLIENTS_VIEW_RATES)) {
            $columns += ['currency' => 'Currency', 'payment_plan' => 'Payment plan'];
        }

        return $columns;
    }

    /** @return Builder<*> */
    public function query(User $user, array $filters): Builder
    {
        return Customer::query()->with(['country', 'paymentPlan']);
    }

    public function row(Model $record, User $user): array
    {
        /** @var Customer $customer */
        $customer = $record;

        $row = [
            'company' => $customer->company_name,
            'contact' => mb_trim($customer->name.' '.$customer->surname),
            'email' => $customer->email,
            'phone' => $customer->phone,
            'country' => $customer->country->name,
            'priority' => $customer->priority->value,
            'status' => $customer->is_active ? 'active' : 'deactivated',
        ];

        if ($user->can(Permissions::CLIENTS_VIEW_RATES)) {
            $row += ['currency' => $customer->currency_code, 'payment_plan' => $customer->paymentPlan?->name];
        }

        return $row;
    }
}
