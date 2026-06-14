<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public $token;

    public function __construct($token)
    {
        $this->token = $token;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url(config('app.url') . '?reset=' . $this->token . '&email=' . urlencode($notifiable->getEmailForPasswordReset()));

        return (new MailMessage)
            ->subject('إعادة تعيين كلمة المرور')
            ->greeting('مرحباً!')
            ->line('لقد تلقيت هذه الرسالة لأننا تلقينا طلباً لإعادة تعيين كلمة المرور لحسابك.')
            ->action('إعادة تعيين كلمة المرور', $url)
            ->line('تنتهي صلاحية رابط إعادة تعيين كلمة المرور هذا خلال ' . config('auth.passwords.'.config('auth.defaults.passwords').'.expire') . ' دقيقة.')
            ->line('إذا لم تقم بطلب إعادة تعيين كلمة المرور، فلا حاجة لاتخاذ أي إجراء آخر.')
            ->salutation('مع تحياتنا،' . "\n" . config('app.name'));
    }
}
