<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\Actions\StopImpersonation;
use Illuminate\Http\RedirectResponse;

final class StopImpersonationController
{
    public function __invoke(StopImpersonation $stop): RedirectResponse
    {
        abort_unless($stop->handle(), 403);

        return redirect('/app');
    }
}
