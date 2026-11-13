<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Models;

use App\Domain\Shared\LogsBusinessActivity;
use Database\Factories\LabelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'color'])]
#[UseFactory(LabelFactory::class)]
final class Label extends Model
{
    /** @use HasFactory<LabelFactory> */
    use HasFactory;

    use LogsBusinessActivity;
}
