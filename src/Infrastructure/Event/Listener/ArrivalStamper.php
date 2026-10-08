<?php

declare(strict_types=1);

namespace App\Infrastructure\Event\Listener;

use App\Domain\Enum\Mail\ArrivalPlace;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Entity\Mail\Message;
use App\Service\Mail\ProviderAcceptTime;
use App\Service\Mail\SyncOrigin;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * Writes down, as a message is first stored, the three things about its
 * arrival that cannot be worked out later: when the provider took delivery of
 * it, what made plMail go and fetch it, and where it had been filed.
 *
 * AT PERSIST, IN ONE PLACE, rather than in the three builders that make a
 * Message out of an IMAP fetch, a Gmail payload and a Graph resource. They
 * agree on nothing except that the row ends up here, and a fact recorded by
 * two of three providers is a column nobody can rely on. By the time persist
 * is called the builder has finished: the headers, the labels and the folder
 * are all on the object.
 *
 * Only on the way in. A message is stamped once and the stamp is never
 * revised — mail moved to the archive an hour later still arrived in the
 * inbox, which is the whole reason $arrivedIn is a column and not a join.
 *
 * Mail written here — a draft, a sent copy — has no `Received:` header and is
 * stored outside any sync, so it leaves this with all three columns empty,
 * which is correct: it did not arrive.
 */
#[AsEntityListener(event: Events::prePersist, method: 'prePersist', entity: Message::class)]
final readonly class ArrivalStamper
{
    public function __construct(
        private ProviderAcceptTime $acceptTime,
        private SyncOrigin         $origin,
    ) {}

    public function prePersist(Message $message): void
    {
        $message->arrivedBy ??= $this->origin->current();

        $this->stamp($message);
    }

    /**
     * Record when the provider accepted the message and where it was filed,
     * if the message can say. Does nothing to one that already has.
     *
     * Public for MessageArrivalBackfillTask, which asks the same of mail that
     * was stored before this listener existed.
     *
     * @return bool whether the message has an accept time now
     */
    public function stamp(Message $message): bool
    {
        if (null !== $message->providerAcceptedAt) {
            return true;
        }

        $acceptedAt = $this->acceptTime->fromHeaders($message->headers);

        // Graph hands over `receivedDateTime`, which is Exchange's own record
        // of the same moment, and does not always hand over the headers.
        if (null === $acceptedAt && null !== $message->graphId) {
            $acceptedAt = $message->receivedAt;
        }

        if (null === $acceptedAt) {
            return false;
        }

        $message->providerAcceptedAt = $acceptedAt;
        $message->arrivedIn          = self::placeOf($message);

        return true;
    }

    /**
     * Where the message is filed, asked of whichever of the three models the
     * row uses: an IMAP folder, Gmail's label ids, or — Graph — the folder's
     * label.
     *
     * The folder first. A row an IMAP account stored can go on to collect
     * Gmail's label ids when Gmail fetches the same mailbox, and the folder is
     * where it actually landed.
     *
     * For mail the backfill stamps, "when it was stored" is no longer on
     * offer and this is where it is now — right for nearly all of a week's
     * mail, and wrong for the inbox mail somebody has since archived.
     */
    private static function placeOf(Message $message): ArrivalPlace
    {
        $mailbox = $message->mailbox;

        if (null !== $mailbox) {
            return match (true) {
                MailboxSpecialUse::JUNK === $mailbox->specialUse   => ArrivalPlace::Spam,
                MailboxSpecialUse::INBOX === $mailbox->specialUse,
                'INBOX' === strtoupper((string) $mailbox->fullPath) => ArrivalPlace::Inbox,
                default                                            => ArrivalPlace::Elsewhere,
            };
        }

        $gmailLabels = $message->gmailLabelIds;

        if (null !== $gmailLabels) {
            return match (true) {
                true === in_array('SPAM', $gmailLabels, true)  => ArrivalPlace::Spam,
                true === in_array('INBOX', $gmailLabels, true) => ArrivalPlace::Inbox,
                default                                        => ArrivalPlace::Elsewhere,
            };
        }

        $place = ArrivalPlace::Elsewhere;

        foreach ($message->labels as $label) {
            if (LabelRole::Spam === $label->role) {
                return ArrivalPlace::Spam;
            }

            if (LabelRole::Inbox === $label->role) {
                $place = ArrivalPlace::Inbox;
            }
        }

        return $place;
    }
}
