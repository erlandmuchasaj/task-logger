<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Models;

use App\Domain\Shared\LogsBusinessActivity;
use Database\Factories\LeaveTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['name', 'affects_balance', 'is_active'])]
#[UseFactory(LeaveTypeFactory::class)]
final class LeaveType extends Model
{
    /** @use HasFactory<LeaveTypeFactory> */
    use HasFactory;

    use LogsBusinessActivity;

    /** The stored name is a slug such as `unpaid_leave`; people see it as "Unpaid Leave". */
    public function label(): string
    {
        return Str::headline($this->name);
    }

    protected function casts(): array
    {
        return ['affects_balance' => 'boolean', 'is_active' => 'boolean'];
    }
}
