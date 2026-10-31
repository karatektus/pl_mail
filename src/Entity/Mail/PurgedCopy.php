<?php

declare(strict_types=1);

namespace App\Entity\Mail;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A server copy of mail deleted forever here, whose deletion on the server
 * has not been confirmed yet (#427).
 *
 * A purge erases the message at once and asks the server afterwards, in a job.
 * In between, the server still has the copy, and a sync of its folder met it
 * as mail it had never seen: "Delete forever" made the mail vanish, and the
 * next sync brought it back. So each copy a purge sends to the server leaves
 * this behind, the sync and the history import pass over its address, and it
 * goes when the server confirms — or when the folder's listing no longer has
 * the UID, which is the same answer.
 *
 * If the server refused the delete, the sync asks again (PurgedCopies): the
 * mail stays deleted here either way, and on the server it goes in the end.
 *
 * The email's identity goes on the record as well as the address. A move this
 * installation queued can land on the server between the purge and its job —
 * the copy then has a UID nobody wrote down — and another client may move it
 * too. A copy of a purged email that turns up anywhere in the account is
 * recognised by the index pass as such (ImapCopies), recorded here in turn,
 * and deleted, never imported.
 */
#[ORM\Entity]
#[ORM\Table(name: 'purged_copy')]
#[ORM\UniqueConstraint(name: 'uniq_purged_copy_mailbox_imap_uid', columns: ['mailbox_id', 'imap_uid'])]
// The index pass looks a page of new copies up by Message-ID.
#[ORM\Index(name: 'idx_purged_copy_message_id', columns: ['message_id'])]
class PurgedCopy
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column]
    public private(set) DateTimeImmutable $purgedAt;

    /**
     * The folder's UIDVALIDITY when the copy was purged. A UID names this copy
     * only while that holds: after a rebuild the same number may be another
     * message, and nothing may be deleted on this record's word.
     */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    public private(set) ?int $uidValidity;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public private(set) ?string $messageId = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?int $rfc822Size = null;

    #[ORM\Column(length: 100, nullable: true)]
    public private(set) ?string $serverEmailId = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public private(set) Mailbox $mailbox,
        #[ORM\Column]
        public private(set) int $imapUid,
        ?string $messageId = null,
        ?int $rfc822Size = null,
        ?string $serverEmailId = null,
    ) {
        $this->purgedAt      = new DateTimeImmutable();
        $this->uidValidity   = $mailbox->uidValidity;
        $this->messageId     = $messageId;
        $this->rfc822Size    = $rfc822Size;
        $this->serverEmailId = $serverEmailId;
    }
}
