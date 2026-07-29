<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LabelBindingRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Where a user-level Label is materialized on one account.
 *
 * Label answers "what does the user call this"; LabelBinding answers "and what
 * is it called at the provider". One row per (label, account) the label has
 * ever been used on — created lazily by LabelResolver, never surfaced in the
 * UI. A label with no binding on an account simply has not been applied to any
 * of that account's mail yet.
 *
 * All three providers land here — IMAP folder, Gmail label id, Graph folder
 * id — so one row is the complete answer to "how is this label materialized on
 * this account". Mailbox::getLabel() reads through this row rather than
 * carrying its own FK; keeping a second link there would just recreate, in
 * mirror image, the asymmetry this table exists to remove.
 *
 * JMAP identifies Mailboxes by binding id, not label id — a JMAP account is a
 * plMail Account, so the per-account row is what has a stable id there.
 */
#[ORM\Entity(repositoryClass: LabelBindingRepository::class)]
#[ORM\Table(name: 'label_binding')]
#[ORM\UniqueConstraint(name: 'uniq_label_binding_label_account', columns: ['label_id', 'account_id'])]
#[ORM\Index(name: 'idx_label_binding_gmail_label_id', columns: ['gmail_label_id'])]
#[ORM\Index(name: 'idx_label_binding_graph_folder_id', columns: ['graph_folder_id'])]
#[ORM\Index(name: 'idx_label_binding_account', columns: ['account_id'])]
class LabelBinding
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Label::class, inversedBy: 'bindings')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public private(set) ?Label $label = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public private(set) ?Account $account = null;

    /**
     * Gmail API label id (e.g. "INBOX", "Label_123"). Null until the label is
     * pushed — ApplyGmailLabelsHandler::ensureRemoteLabel() fills it in on
     * first use.
     */
    #[ORM\Column(length: 255, nullable: true)]
    public private(set) ?string $gmailLabelId = null;

    #[ORM\Column(length: 512, nullable: true)]
    public private(set) ?string $graphFolderId = null;

    /**
     * The IMAP folder this label is fed by on this account. Null for
     * API-provider accounts and for labels that exist only locally.
     */
    #[ORM\OneToOne(inversedBy: 'labelBinding')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public private(set) ?Mailbox $mailbox = null;

    #[ORM\Column]
    public private(set) ?DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    public private(set) ?DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function setLabel(?Label $label): static
    {
        $this->label = $label;

        if (null !== $label) {
            $label->addBinding($this);
        }

        return $this;
    }

    public function setAccount(?Account $account): static
    {
        $this->account = $account;

        return $this;
    }

    public function setGmailLabelId(?string $gmailLabelId): static
    {
        $this->gmailLabelId = $gmailLabelId;

        return $this;
    }

    public function setGraphFolderId(?string $graphFolderId): static
    {
        $this->graphFolderId = $graphFolderId;

        return $this;
    }

    public function setMailbox(?Mailbox $mailbox): static
    {
        $this->mailbox = $mailbox;

        return $this;
    }

    public function setUpdatedAt(DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
