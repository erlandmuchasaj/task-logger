<?php

declare(strict_types=1);

namespace App\Domain\People\Notifications;

use App\Domain\Identity\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** D-76: the thank-you email on the last working day. Email only: the person may no longer be able to sign in to read the app. */
final class OffboardingThanks extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Thank you, '.$notifiable->name)
            ->greeting("Dear {$notifiable->name},")
            ->line('Today is your last working day. Thank you for everything you have done with us.')
            ->line('We wish you all the best for what comes next.');
    }
}
