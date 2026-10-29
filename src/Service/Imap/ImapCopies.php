<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Domain\DTO\Mail\ImapCopyIdentity;
use App\Domain\Helper\MessageIdHelper;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Infrastructure\ImapClient\ImapSession;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Mail\MessageRepository;
use App\Service\Label\ThreadLabelSynchronizer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The copies a server keeps of each email, as locations of one message (#415):
 * recognising a new copy of stored mail instead of downloading it, and
 * forgetting a copy the server no longer keeps without forgetting the email.
 *
 * ── Recognising ─────────────────────────────────────────────────────────────
 * Before a folder's new mail is downloaded, find the copies of email that is
 * already here, and give that email one more location instead.
 *
 * A server keeps one email in several folders more often than not: Proton
 * Bridge files everything in All Mail as well, Gmail over IMAP shows a label
 * as a folder, a client copies. Each copy used to be downloaded in full and
 * stored as an email of its own, so a conversation showed it twice (#414) and
 * a second folder of that kind cost a second full download.
 *
 * So the folder is asked for an index first — `UID FETCH` of the size, the
 * Message-ID header and, where the server has one, its own id for the email:
 * a few hundred bytes a message where the body is tens of kilobytes. A copy
 * that matches stored mail becomes a location of it. Only the rest are
 * downloaded, and they keep what the index said about them (identities()) so
 * the next copy of them is recognised in turn.
 *
 * ── What counts as the same email ────────────────────────────────────────────
 * The server's own id where it gives one: Gmail's X-GM-MSGID, RFC 8474's
 * EMAILID. It means exactly this, and it decides alone.
 *
 * Otherwise the Message-ID, and only with the size beside it. A Message-ID by
 * itself is not an identity: a list resends under the same one, and a message
 * you Cc'd to yourself is in Sent and in INBOX with the same one while being
 * two messages here, as it always was — the delivered copy has gained
 * Received: headers, so the sizes differ and it stays apart. Measured on
 * Dovecot 2.4: COPY keeps RFC822.SIZE (and INTERNALDATE) to the octet.
 *
 * Never across accounts: Gmailify's links between a Gmail mailbox and a
 * sibling's own copies are deliberate and are not this class's to fold. Never
 * mail with no Message-ID, and never a row stored before #415, which has no
 * size recorded — those duplicates are the merge job's.
 */
final readonly class ImapCopies
{
    /** UIDs per index FETCH: small answers, so a large page costs one round trip. */
    private const int INDEX_PAGE = 500;

    public function __construct(
        private MessageRepository       $messages,
        private StateManager            $stateManager,
        private ThreadLabelSynchronizer $threadLabels,
        private EntityManagerInterface  $em,
        private LoggerInterface         $logger,
    ) {
    }

    /**
     * Attach every copy among $uids that is stored mail already, and say what
     * the rest are.
     *
     * Flushes what it attached, so the caller may clear the entity manager.
     *
     * @param list<int> $uids new to this folder
     *
     * @return array{list<int>, array<int, ImapCopyIdentity>} the UIDs attached,
     *         and the identity of every UID left to download, by UID
     */
    public function attachKnownCopies(Mailbox $mailbox, ImapSession $session, array $uids): array
    {
        if ([] === $uids) {
            return [[], []];
        }

        $identities = $this->index($session, $mailbox->serverPath(), $uids);
        $attached   = [];

        foreach (array_chunk($identities, self::INDEX_PAGE, true) as $page) {
            $candidates = $this->messages->findCopyCandidates(
                $mailbox->account,
                array_values(array_unique(array_filter(array_map(static fn (ImapCopyIdentity $copy): string => $copy->messageId, $page)))),
                array_values(array_unique(array_filter(array_map(static fn (ImapCopyIdentity $copy): ?string => $copy->serverId, $page)))),
            );

            if ([] === $candidates) {
                continue;
            }

            foreach ($page as $uid => $copy) {
                $email = self::sameEmail($copy, $candidates);

                if (null === $email) {
                    continue;
                }

                $this->attach($email, $mailbox, $uid);
                $attached[] = $uid;
                unset($identities[$uid]);
            }
        }

        if ([] !== $attached) {
            $this->em->flush();

            $this->logger->info('Recognised copies of stored mail; not downloading them again', [
                'mailbox'  => $mailbox->fullPath,
                'attached' => count($attached),
            ]);
        }

        return [$attached, $identities];
    }

    /**
     * Forget every copy this folder had of the email, and leave the email.
     *
     * For a copy the server no longer keeps there while it keeps others: the
     * sweep's absent UID, a folder that is gone. The folder's label comes off
     * unless another copy is in a folder that wears it too. Whether a message
     * with no copy left is gone is not this method's call — callers only bring
     * it messages that have another — see VanishedMessageReconciler.
     *
     * Not flushed; the caller flushes.
     */
    public function forget(Message $email, Mailbox $mailbox): void
    {
        foreach ($email->locations->toArray() as $location) {
            if ($location->mailbox === $mailbox) {
                $email->removeLocation($location);
            }
        }

        $label = $mailbox->label;

        if (null !== $label) {
            foreach ($email->locations as $location) {
                if ($location->mailbox->label === $label) {
                    $label = null;

                    break;
                }
            }
        }

        if (null !== $label) {
            $email->removeLabel($label);
        }

        $accountId = (int) $email->account->id;

        if (null !== $email->thread) {
            $this->threadLabels->sync($email->thread);
            $this->stateManager->recordThreadsTouched($accountId, [(int) $email->thread->id]);
        }

        $this->stateManager->recordUpdated($accountId, JmapObjectType::Email, (string) $email->id);
    }

    /**
     * @param list<Message> $candidates
     */
    public static function sameEmail(ImapCopyIdentity $copy, array $candidates): ?Message
    {
        if (null !== $copy->serverId) {
            foreach ($candidates as $candidate) {
                if ($candidate->serverEmailId === $copy->serverId) {
                    return $candidate;
                }
            }
        }

        if ('' === $copy->messageId || null === $copy->size) {
            return null;
        }

        foreach ($candidates as $candidate) {
            if (
                $candidate->messageId === $copy->messageId
                && $candidate->rfc822Size === $copy->size
                // Two ids that differ are two emails, whatever else agrees.
                && (null === $copy->serverId || null === $candidate->serverEmailId)
            ) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param list<int> $uids
     *
     * @return array<int, ImapCopyIdentity> by UID, in the order asked
     */
    private function index(ImapSession $session, string $path, array $uids): array
    {
        $serverIdItem = match (true) {
            $session->connection->supports('X-GM-EXT-1') => 'X-GM-MSGID',
            $session->connection->supports('OBJECTID')   => 'EMAILID',
            default                                      => null,
        };

        $items = ['RFC822.SIZE', 'BODY.PEEK[HEADER.FIELDS (MESSAGE-ID)]'];

        if (null !== $serverIdItem) {
            $items[] = $serverIdItem;
        }

        $identities = [];

        foreach (array_chunk($uids, self::INDEX_PAGE) as $chunk) {
            $fetched = $session->uidFetch($path, ImapSession::uidSet($chunk), $items);

            foreach ($chunk as $uid) {
                $answer = $fetched[$uid] ?? null;

                // A UID the index has nothing for is downloaded like any other;
                // the full fetch is what decides whether it can be read.
                if (null === $answer) {
                    $identities[$uid] = new ImapCopyIdentity($uid, null, '', null);

                    continue;
                }

                $identities[$uid] = new ImapCopyIdentity(
                    $uid,
                    self::size($answer['RFC822.SIZE'] ?? null),
                    self::messageId($answer['BODY[HEADER.FIELDS (MESSAGE-ID)]'] ?? null),
                    null === $serverIdItem ? null : self::serverId($answer[$serverIdItem] ?? null),
                );
            }
        }

        return $identities;
    }

    private function attach(Message $email, Mailbox $mailbox, int $uid): void
    {
        $email->addLocation($mailbox, $uid);

        // The folder's label, as a downloaded copy would have got — except a
        // mirror's: All Mail is everywhere the email is, and says nothing
        // about where that is.
        $label = $mailbox->label;

        if (null !== $label && false === $mailbox->mirrorsOtherFolders) {
            $email->addLabel($label);
            $email->thread?->addLabel($label);

            $accountId = (int) $mailbox->account->id;

            $this->stateManager->recordUpdated($accountId, JmapObjectType::Email, (string) $email->id);

            if (null !== $email->thread) {
                $this->stateManager->recordThreadsTouched($accountId, [(int) $email->thread->id]);
            }
        }
    }

    private static function size(mixed $value): ?int
    {
        return true === is_string($value) && 1 === preg_match('~^\d+$~', $value) ? (int) $value : null;
    }

    private static function messageId(mixed $block): string
    {
        if (false === is_string($block)) {
            return '';
        }

        // Unfolded first: a long Message-ID may continue on the next line.
        $unfolded = (string) preg_replace('~\r?\n[ \t]+~', ' ', $block);

        if (1 !== preg_match('~^Message-ID:[ \t]*(.*?)[ \t]*$~mi', $unfolded, $match)) {
            return '';
        }

        return MessageIdHelper::normalise($match[1]);
    }

    /**
     * X-GM-MSGID is a number; EMAILID a parenthesised one-item list.
     */
    private static function serverId(mixed $value): ?string
    {
        if (true === is_array($value)) {
            $value = $value[0] ?? null;
        }

        return true === is_string($value) && '' !== $value ? $value : null;
    }
}
