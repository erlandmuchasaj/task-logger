<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Data\CustomerData;
use App\Domain\Clients\Models\Customer;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/** W-02 step 2: create a client. Perm clients.create (superadmin, admin). Rates are added separately. */
final class CreateCustomer
{
    use ValidatesCustomer;

    public function handle(User $actor, CustomerData $data): Customer
    {
        Gate::forUser($actor)->authorize('create', Customer::class);
        $checked = $this->validateCustomer($data, null);

        return resolve(CauserResolver::class)->withCauser($actor, fn (): Customer => Customer::query()->create([
            'created_by' => $actor->getKey(),
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
            'is_active' => $data->isActive,
        ]));
    }
}
