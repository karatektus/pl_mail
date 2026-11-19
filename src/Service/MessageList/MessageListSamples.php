<?php

declare(strict_types=1);

namespace App\Service\MessageList;

use App\Domain\Enum\Mail\MessageFlag;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\Mail\MessageThread;
use DateTimeImmutable;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The conversations the Message list page draws as its preview (#608).
 *
 * ── Why real rows of made-up mail ───────────────────────────────────────────
 * The preview is the list's own row template, given conversations that exist
 * only for this request. A miniature drawn by hand — which the Appearance page
 * had until #577 — is a second copy of the row, and the copy is right until
 * the row changes; this one cannot drift, because there is nothing to drift
 * from. The settings page then only has to set the same attributes on <html>
 * that the list reads, and the preview follows every click the way the real
 * list would.
 *
 * Made up rather than the reader's newest mail, because the preview has to
 * show what each option does, and a real inbox may have no attachment, no
 * label and no conversation in it today. Between them the four rows have
 * each: unread and read, a conversation of three, a label, files, a star, an
 * answered one.
 *
 * ── What keeps them from being mail ─────────────────────────────────────────
 * Never persisted: nothing here is handed to the entity manager, and the
 * account they name holds no reference back to them, so no flush can find
 * them. The ids are set because the row builds its links from them, and they
 * are far above anything a real install reaches; the preview is `inert`, so
 * nothing can follow a link anyway. The account is the reader's own, so their
 * address reads as "me" and a multi-account install shows its corner.
 */
final readonly class MessageListSamples
{
    /** Clear of any real id. The row only builds links from these. */
    private const int ID_BASE = 2_000_000_000;

    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * @param list<Account> $accounts the reader's accounts, the first of which
     *                                the samples arrive on
     *
     * @return list<MessageThread>
     */
    public function threads(array $accounts): array
    {
        $account = $accounts[0] ?? null;
        $own     = null !== $account ? (string) $account->email : 'me@example.org';
        $now     = new DateTimeImmutable();

        $travel = $this->label(1, 'travel', 'blue');
        $work   = $this->label(2, 'work', 'green');

        return [
            $this->thread(1, $account, 'flights', unread: true, labels: [$travel], messages: [
                $this->message(11, 'Anna Berger', 'anna@example.org', $now->modify('-3 hours'), 'flights'),
                $this->message(12, null, $own, $now->modify('-2 hours'), 'flights', seen: true),
                $this->message(13, 'Ben Ortiz', 'ben@example.org', $now->modify('-40 minutes'), 'flights', files: [
                    [101, 'itinerary.pdf', 'application/pdf'],
                    [102, 'boarding-pass.png', 'image/png'],
                ]),
            ]),
            $this->thread(2, $account, 'contract', starred: true, labels: [$work], messages: [
                $this->message(21, 'Chris Meyer', 'chris@example.org', $now->modify('-1 day'), 'contract', seen: true, answered: true, files: [
                    [201, 'contract-v3.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
                ]),
            ]),
            $this->thread(3, $account, 'digest', messages: [
                $this->message(31, 'Studio Weekly', 'news@example.org', $now->modify('-4 days'), 'digest', seen: true),
            ]),
            $this->thread(4, $account, 'offsite', unread: true, labels: [$work, $travel], messages: [
                $this->message(41, 'Dana Kim', 'dana@example.org', $now->modify('-40 days'), 'offsite', seen: true),
                $this->message(42, 'Eli Novak', 'eli@example.org', $now->modify('-39 days'), 'offsite'),
            ]),
        ];
    }

    /**
     * @param list<Label>   $labels
     * @param list<Message> $messages oldest first
     */
    private function thread(int $n, ?Account $account, string $key, array $messages, bool $unread = false, bool $starred = false, array $labels = []): MessageThread
    {
        $thread = new MessageThread();
        self::identify($thread, self::ID_BASE + $n);

        $thread->account       = $account;
        $thread->subject       = $this->translator->trans(sprintf('settings.message_list.sample.%s.subject', $key));
        $thread->starredAt     = true === $starred ? new DateTimeImmutable() : null;
        $thread->messageCount  = count($messages);
        $thread->unreadCount   = true === $unread ? 1 : 0;
        $thread->lastMessageAt = $messages[count($messages) - 1]->receivedAt;

        foreach ($messages as $message) {
            if (null !== $account) {
                $message->account = $account;
            }

            $thread->addMessage($message);
            $thread->attachmentCount += $message->messageParts->count();
        }

        foreach ($labels as $label) {
            $thread->addLabel($label);
        }

        return $thread;
    }

    /** @param list<array{int, string, string}> $files id, name, type */
    private function message(int $n, ?string $name, string $address, DateTimeImmutable $at, string $key, bool $seen = false, bool $answered = false, array $files = []): Message
    {
        $message = new Message();
        self::identify($message, self::ID_BASE + $n);

        $message->fromName       = $name;
        $message->fromAddress    = $address;
        $message->receivedAt     = $at;
        $message->seenAt         = true === $seen ? $at : null;
        $message->subject        = $this->translator->trans(sprintf('settings.message_list.sample.%s.subject', $key));
        $message->bodyText       = $this->translator->trans(sprintf('settings.message_list.sample.%s.body', $key));
        $message->flags          = true === $answered ? [MessageFlag::ANSWERED->value] : [];
        $message->hasAttachments = [] !== $files;

        foreach ($files as [$id, $filename, $type]) {
            $part = new MessagePart();
            self::identify($part, self::ID_BASE + $id);

            $part->filename    = $filename;
            $part->contentType = $type;
            $part->disposition = 'attachment';
            $part->isInline    = false;

            $message->addMessagePart($part);
        }

        return $message;
    }

    private function label(int $n, string $key, string $color): Label
    {
        $label = new Label();
        self::identify($label, self::ID_BASE + $n);

        $label->name  = $this->translator->trans(sprintf('settings.message_list.sample.label.%s', $key));
        $label->color = $color;

        return $label;
    }

    /**
     * The id, which every one of these entities only lets Doctrine write —
     * `private(set)` — and the row needs for its links. Reflection is the
     * one way in that does not add a setter to the entities for this.
     */
    private static function identify(object $entity, int $id): void
    {
        new \ReflectionProperty($entity, 'id')->setValue($entity, $id);
    }
}
