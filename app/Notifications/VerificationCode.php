<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Mail z 6-cyfrowym kodem potwierdzającym adres e-mail (App\Models\User::sendEmailVerificationNotification). */
class VerificationCode extends Notification
{
    public function __construct(public string $code, public int $minutes) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your LechTYPER code: :code', ['code' => $this->code]))
            ->greeting(__('Hello!'))
            ->line(__('Enter this code to confirm your email address:'))
            ->line('**'.$this->code.'**')
            ->line(__('The code is valid for :minutes minutes.', ['minutes' => $this->minutes]))
            ->action(__('Enter the code'), route('verification.notice'))
            ->line(__('If you did not create an account, ignore this message.'));
    }
}
