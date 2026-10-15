<?php

declare(strict_types=1);

namespace App\Entity\Mail;

use App\Domain\Enum\Mail\GmailSkipReason;
use App\Repository\Mail\GmailSkippedMessageRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A message Gmail lists that plMail looked at and did not store.
 *
 * ── Why it is written down ──────────────────────────────────────────────────
 * It used not to be. A message the batch handler declined left a debug line
 * and nothing else, so nothing knew it had been looked at — and the import's
 * only test for "is this one still to come" is "is it stored". Every hourly
 * listing therefore found the same messages missing, fetched every one of them
 * again in full, and declined them again. On the mailbox this was reported
 * from (#90) that was 2,235 messages, 134,000 quota units an hour out of the
 * 360,000 the project is allowed, for a day: the import only ended when it had
 * run out of attempts, and said "waiting" until then.
 *
 * With a row here the listing leaves the message alone, an import whose every
 * outstanding message has an answer is finished at the end of its first pass,
 * and the progress line counts it as dealt with rather than as still coming.
 *
 * ── And what it is for afterwards ───────────────────────────────────────────
 * Whether those messages SHOULD be declined is a separate question, and it
 * cannot be answered without knowing what they are. So the row keeps what the
 * decision was made on — the labels, and the four address headers — and
 * `app:gmail:skipped` prints it. Not the subject and not the body: this is a
 * record of why, not a second copy of the mail.
 *
 * A row is removed when the message is stored after all, and by
 * `app:gmail:skipped --forget`, which makes the next listing ask again.
 *
 * Keyed by the account the message was listed for. The id is Gmail's, which is
 * only unique within a mailbox.
 */
#[ORM\Entity(repositoryClass: GmailSkippedMessageRepository::class)]
#[ORM\Table(name: 'gmail_skipped_message')]
#[ORM\UniqueConstraint(name: 'uniq_gmail_skipped_message', columns: ['account_id', 'gmail_id'])]
class GmailSkippedMessage
{
    /** How much of a header is kept. A To line can run to hundreds of addresses. */
    private const int HEADER_LENGTH = 300;

    private const array HEADERS = ['from', 'to', 'cc', 'delivered-to'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public private(set) Account $account;

    #[ORM\Column(length: 64)]
    public private(set) string $gmailId;

    #[ORM\Column(length: 20, enumType: GmailSkipReason::class)]
    public private(set) GmailSkipReason $reason;

    /**
     * Gmail's labels on the message when it was looked at — DRAFT, CHAT, SENT,
     * SPAM and the rest. Usually the whole explanation.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    public private(set) array $labelIds = [];

    /**
     * From, To, Cc and Delivered-To as they arrived, shortened. What the
     * ownership filter read; empty for a message that could not be fetched.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: 'json')]
    public private(set) array $headers = [];

    #[ORM\Column]
    public private(set) DateTimeImmutable $skippedAt;

    /**
     * @param list<string>          $labelIds
     * @param array<string, string> $headers  lower-cased header name → value; only the address headers are kept
     */
    public function __construct(
        Account $account,
        string $gmailId,
        GmailSkipReason $reason,
        array $labelIds = [],
        array $headers = [],
    ) {
        $this->account   = $account;
        $this->gmailId   = $gmailId;
        $this->reason    = $reason;
        $this->labelIds  = array_values($labelIds);
        $this->skippedAt = new DateTimeImmutable();

        foreach (self::HEADERS as $name) {
            $value = trim((string) ($headers[$name] ?? ''));

            if ('' !== $value) {
                $this->headers[$name] = mb_substr($value, 0, self::HEADER_LENGTH);
            }
        }
    }
}
