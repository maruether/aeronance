<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Core\Models\BreakGlassRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the other administrators that emergency access was granted.
 *
 * Not queued: this runs in situations where the queue worker may be among the
 * things that are broken, and a notification sitting in a queue nobody drains
 * is no notification at all.
 */
final class BreakGlassUsed extends Notification
{
    use Queueable;

    public function __construct(private readonly BreakGlassRecord $record) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $zone = config('aeronance.organisation.timezone');

        return (new MailMessage)
            ->subject('Notfallzugang wurde verwendet')
            ->greeting('Hinweis zur Sicherheit')
            ->line(sprintf(
                'Für das Konto %s wurde ein administrativer Notfallzugang eingerichtet.',
                $this->record->target_email,
            ))
            ->line(sprintf('Zeitpunkt: %s',
                $this->record->granted_at->timezone($zone)->format('d.m.Y H:i')))
            ->line(sprintf('Ausgeführt von: %s auf %s',
                $this->record->shell_user ?? 'unbekannt',
                $this->record->hostname ?? 'unbekanntem Server'))
            ->when($this->record->origin_ip !== null, fn (MailMessage $m): MailMessage => $m->line(
                sprintf('Herkunft: %s', $this->record->origin_ip)))
            ->line(sprintf('Angegebener Grund: %s', $this->record->reason))
            ->line('Wenn Sie davon nichts wissen, prüfen Sie das bitte umgehend.');
    }
}
