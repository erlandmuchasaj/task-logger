<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Domain\Clients\Actions\CreateCustomer as CreateCustomerAction;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Support\CustomerForm;
use App\Filament\Support\ActionErrors;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    public function form(Schema $schema): Schema
    {
        return CustomerForm::configure($schema, null)->statePath('data');
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(CreateCustomerAction::class)->handle(auth()->user(), CustomerForm::data($data, null));
        } catch (ValidationException $e) {
            ActionErrors::report($this, $e);
            throw new Halt;
        }
    }
}
