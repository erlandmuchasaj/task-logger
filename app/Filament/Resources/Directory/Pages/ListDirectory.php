<?php

declare(strict_types=1);

namespace App\Filament\Resources\Directory\Pages;

use App\Filament\Resources\Directory\DirectoryResource;
use Filament\Resources\Pages\ListRecords;

class ListDirectory extends ListRecords
{
    protected static string $resource = DirectoryResource::class;
}
