<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Data\CustomerData;
use App\Domain\Clients\Models\Customer;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/** Perm clients.update. Activation is a separate action (clients.activate / clients.deactivate). */
class UpdateCustomer
{
    use ValidatesCustomer;

    public function handle(User $actor, Customer $customer, CustomerData $data): Customer
    {
        Gate::forUser($actor)->authorize('update', $customer);
        $checked = $this->validateCustomer($data, $customer);

        return app(CauserResolver::class)->withCauser($actor, function () use ($customer, $data, $checked): Customer {
            $customer->update([
                'name' => $data->name,
                'surname' => $data->surname,
                'email' => $data->email,
                'country_id' => $data->countryId,
                'phone' => $checked['phone'],
                'address' => $data->address,
                'company_name' => $data->companyName,
                'priority' => $data->priority,
                'payment_plan_id' => $data->paymentPlanId,
                'currency_code' => $checked['currency'],
                'avatar_path' => $data->avatarPath,
            ]);

            return $customer->refresh();
        });
    }
}
