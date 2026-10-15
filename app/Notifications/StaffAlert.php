<?php

namespace App\Notifications;

use App\Hooks\HookCatalog;
use App\Hooks\HookEvent;
use App\Hooks\Hooks;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifier;

/**
 * An email to staff about something that needs a person: a failed
 * provisioning action, a payment dispute, a manual fulfillment task. Sent
 * from the queue, and only once the change that caused it is saved.
 */
class StaffAlert extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    /** @param  list<string>  $lines */
    public function __construct(public string $subject, public array $lines, public string $actionText, public string $url) {}

    /** Emails every staff member whose role has the ability, e.g. manage-billing for disputes. */
    public static function send(string $ability, string $subject, array $lines, string $actionText, string $url): void
    {
        self::sendTo(User::all()->filter(fn (User $user) => $user->allows($ability)), $subject, $lines, $actionText, $url);
    }

    /** @param  iterable<User>  $staff */
    public static function sendTo(iterable $staff, string $subject, array $lines, string $actionText, string $url): void
    {
        // Chat channels and webhooks hear about it too.
        app(Hooks::class)->fire(new HookEvent(HookCatalog::STAFF_ALERT, ['subject' => $subject, 'lines' => array_values($lines), 'url' => $url], $subject, $url));

        $staff = collect($staff);

        if ($staff->isNotEmpty()) {
            Notifier::send($staff, new self($subject, $lines, $actionText, $url));
        }
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject('['.config('app.name').'] '.$this->subject);

        foreach ($this->lines as $line) {
            $message->line($line);
        }

        return $message->action($this->actionText, $this->url);
    }
}
