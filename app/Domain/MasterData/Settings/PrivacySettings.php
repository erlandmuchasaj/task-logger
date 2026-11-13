<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Settings;

use Spatie\LaravelSettings\Settings;

/** D-15: months after the offboarding end date before personal data is anonymised (BR-28). */
class PrivacySettings extends Settings
{
    public int $anonymize_after_months;

    public static function group(): string
    {
        return 'privacy';
    }
}
