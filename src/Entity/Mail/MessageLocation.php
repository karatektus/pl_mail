<?php

declare(strict_types=1);

namespace App\Entity\Mail;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One place a server keeps a message: a folder, and the UID it has there.
 *
 * A Message is the email — headers, bodies, parts, thread, labels. Where it is
 * kept is a separate fact, and on IMAP there can be several of them: Proton
 * Bridge files every message in All Mail as well as in its folder, Gmail over
 * IMAP shows each label as a folder, and a user can copy a message anywhere.
 * Until #415 a Message row was one of those copies, its identity the pair
 * (mailbox, UID), and an email the server kept twice was two emails here —
 * shown twice in its conversation (#414), counted twice, found twice.
 *
 * So the UID's uniqueness lives here, where it is true: within one folder a
 * UID names one copy. The email's identity is the Message row.
 *
 * POP3 goes through the same path. Its server has one mailbox and no UIDs, so
 * a POP3 message is a Message with a single location whose $imapUid is null;
 * pop3_uidl remains that sync's memory of what the server holds.
 *
 * ── Transition ───────────────────────────────────────────────────────────────
 * The rest of the code still reads and writes Message::$mailbox and
 * Message::$imapUid. Until it moves here, Message mirrors that pair into its
 * one location on every flush (Message::mirrorLocation()), so the table is
 * complete from the first migration on and readers can be moved one at a
 * time. The mirror and the two columns go once nothing writes them.
 */
#[ORM\Entity]
#[ORM\Table(name: 'message_location')]
// Mailbox leads, as it did on message: the syncer reads every UID of one
// folder back at a time, and no query looks up a bare UID. A null UID — a
// copy whose new address the server has not reported yet, or a POP3 copy —
// does not collide, because Postgres treats NULLs as distinct.
#[ORM\UniqueConstraint(name: 'uniq_message_location_mailbox_imap_uid', columns: ['mailbox_id', 'imap_uid'])]
// Every message's locations are loaded with it.
#[ORM\Index(name: 'idx_message_location_message', columns: ['message_id'])]
class MessageLocation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'locations')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public private(set) Message $message,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Mailbox $mailbox,
        #[ORM\Column(nullable: true)]
        public ?int $imapUid = null,
    ) {
    }

    /**
     * This copy's flags as the server last reported them, canonical
     * (MessageFlag::canonicalList()); null until a listing has said.
     *
     * IMAP keeps flags per copy, and two copies of one email can disagree —
     * another client marks the INBOX copy read and leaves the Archive copy
     * alone. Compared against the message, each folder's listing would then
     * set the message to its own answer, and the two would take turns for
     * ever. Compared against this, a listing changes the message only when
     * this copy changed since it was last read: the last change made anywhere
     * wins, and copies that merely disagree leave it alone. See
     * ImapFlagReconciler.
     *
     * @var list<string>|null
     */
    #[ORM\Column(name: 'server_flags', type: Types::JSON, nullable: true)]
    public ?array $serverFlags = null;
}
