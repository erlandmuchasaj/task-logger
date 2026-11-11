<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Models;

use App\Domain\Shared\LogsBusinessActivity;
use Database\Factories\TechnologyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Skill and Area merged (D-01). UI label: Technologies.
 *
 * @property-read Pivot $pivot set when loaded through User::technologies() (carries the skill level)
 */
#[Fillable(['name', 'description'])]
#[UseFactory(TechnologyFactory::class)]
class Technology extends Model
{
    /** @use HasFactory<TechnologyFactory> */
    use HasFactory, LogsBusinessActivity;
}
