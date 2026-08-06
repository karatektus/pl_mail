<?php

declare(strict_types=1);

namespace App\Entity\Label;

use App\Domain\Trait\TimestampableTrait;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Repository\Label\LabelBindingRepository;
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
 * this account". Mailbox::$label reads through this row rather than
 * carrying its own FK; keeping a second link there would just recreate, in
 * mirror image, the asymmetry this table exists to remove.
 *
 * JMAP identifies Mailboxes by binding id, not label id — a JMAP account is a
 * plMail Account, so the per-account row is what has a stable id there.
 */
#[ORM\Entity(repositoryClass: LabelBindingRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'label_binding')]
#[ORM\UniqueConstraint(name: 'uniq_label_binding_label_account', columns: ['label_id', 'account_id'])]
#[ORM\Index(name: 'idx_label_binding_gmail_label_id', columns: ['gmail_label_id'])]
#[ORM\Index(name: 'idx_label_binding_graph_folder_id', columns: ['graph_folder_id'])]
#[ORM\Index(name: 'idx_label_binding_graph_category_id', columns: ['graph_category_id'])]
#[ORM\Index(name: 'idx_label_binding_account', columns: ['account_id'])]
class LabelBinding
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    /**
     * Keeps the inverse side in step on assignment. Doctrine hydrates through
     * RawValuePropertyAccessor, which writes the backed value and deliberately
     * skips hooks — so this fires on application writes only, and loading a
     * binding never drags its Label in behind it.
     */
    #[ORM\ManyToOne(targetEntity: Label::class, inversedBy: 'bindings')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Label $label = null {
        set {
            $this->label = $value;
            $value?->addBinding($this);
        }
    }

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Account $account = null;

    /**
     * Gmail API label id (e.g. "INBOX", "Label_123"). Null until the label is
     * pushed — ApplyGmailLabelsHandler::ensureRemoteLabel() fills it in on
     * first use.
     */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $gmailLabelId = null;

    /**
     * The Exchange **folder** this label is backed by, or null.
     *
     * Load-bearing beyond identity: GraphLabelPolicy reads exactly this to
     * decide whether a label pushes as a folder move or as a master category,
     * so anything written here changes how the label behaves at the provider.
     * That is why a category id does NOT live here — see $graphCategoryId.
     */
    #[ORM\Column(length: 512, nullable: true)]
    public ?string $graphFolderId = null;

    /**
     * The Exchange **master category** id (a GUID), or null.
     *
     * Its own column rather than sharing $graphFolderId, which is where it used
     * to be written by the create-a-category path. That was not a naming
     * quibble: GraphLabelPolicy answers "is this label folder-backed?" by
     * looking for a graphFolderId, so a label pushed to Outlook as a category
     * acquired one and, from the next change onwards, was pushed as a folder
     * MOVE instead — silently turning a many-to-many tag into a location.
     *
     * Null on a category that has never been pushed or read back. That is
     * survivable rather than fatal, because a master category's real identity
     * at the provider is its displayName: ApplyLabelStructureHandler falls back
     * to looking one up by the name it had before a rename. The id is what makes
     * that lookup unnecessary, not what makes the rename possible.
     */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $graphCategoryId = null;

    /**
     * The IMAP folder this label is fed by on this account. Null for
     * API-provider accounts and for labels that exist only locally.
     */
    #[ORM\OneToOne(inversedBy: 'labelBinding')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?Mailbox $mailbox = null;

}
