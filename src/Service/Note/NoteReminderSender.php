<?php

declare(strict_types=1);

namespace App\Service\Note;

use App\Entity\Note\Note;
use App\Jmap\Account\CalendarAccountResolver;
use App\Jmap\Push\PushSenderRegistry;
use App\Repository\User\PushSubscriptionRepository;
use App\Service\Mail\MailSenderRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends one note's reminder: a mail the user sends to themselves, and a
 * notification on every device they registered for push — the same two
 * channels, and the same transports, as a calendar alert (EmailAlertChannel,
 * PushAlertChannel). The payload type `NoteReminder` is matched by
 * public/sw.js and by the Android app, which rings it on its Reminders channel.
 *
 * Either channel may have nothing to deliver to (no account, no device); the
 * answer is whether at least one did.
 */
final readonly class NoteReminderSender
{
    public const string PAYLOAD_TYPE = 'NoteReminder';

    public function __construct(
        private CalendarAccountResolver    $accounts,
        private MailSenderRegistry         $mailers,
        private PushSubscriptionRepository $subscriptions,
        private PushSenderRegistry         $pushers,
        private LoggerInterface            $logger,
    ) {
    }

    public function send(Note $note): bool
    {
        $title = self::title($note);
        $body  = self::preview($note);

        $mailed = $this->mail($note, $title, $body);
        $pushed = $this->push($note, $title, $body);

        return $mailed || $pushed;
    }

    public static function title(Note $note): string
    {
        return '' !== $note->title ? $note->title : 'Note reminder';
    }

    /** The first few lines of the note, checklist items included, as plain text. */
    public static function preview(Note $note): string
    {
        $lines = [];

        foreach (explode("\n", $note->body) as $line) {
            $line = trim((string) preg_replace('/^(#|-) /', '', str_replace(['**', '__'], '', $line)));
            if ('' !== $line) {
                $lines[] = $line;
            }
        }

        foreach ($note->items as $item) {
            $lines[] = (true === ($item['done'] ?? false) ? '☑ ' : '☐ ').($item['text'] ?? '');
        }

        return mb_substr(implode("\n", \array_slice($lines, 0, 8)), 0, 500);
    }

    private function mail(Note $note, string $title, string $body): bool
    {
        $account = $this->accounts->accountFor($note->usr);
        $address = $account?->displayAddress;

        if (null === $account || null === $address || '' === $address) {
            return false;
        }

        try {
            $email = new Email()
                ->from(new Address($address, (string) $account->name))
                ->to(new Address($address, (string) $account->name))
                ->subject('Reminder: '.$title)
                ->text(sprintf("%s\n\n%s\n", $title, $body));

            return $this->mailers->resolve($account)->send($email, $account);
        } catch (\Throwable $e) {
            $this->logger->error('NoteReminder: could not send the reminder mail', [
                'noteId' => $note->id,
                'error'  => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function push(Note $note, string $title, string $body): bool
    {
        if (false === $this->pushers->anyConfigured()) {
            return false;
        }

        $payload = [
            '@type'  => self::PAYLOAD_TYPE,
            'noteId' => (string) $note->id,
            'title'  => $title,
            'body'   => $body,
            'url'    => sprintf('/notes/%d', $note->id),
            'tag'    => sprintf('note/%d', $note->id),
        ];

        $delivered = false;

        foreach ($this->subscriptions->findDeliverableForUser((int) $note->usr->id) as $device) {
            $sender = $this->pushers->for($device);

            if (null !== $sender && true === $sender->isConfigured()) {
                $delivered = $sender->send($device, $payload) || $delivered;
            }
        }

        return $delivered;
    }
}
