<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Enum\LabelRole;
use App\Repository\LabelRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The user-facing organizing concept. Messages and threads carry N labels;
 * Mailbox is demoted to pure IMAP sync infrastructure and links to the
 * Label it feeds via Mailbox::$label.
 *
 * System labels carry a LabelRole; user-created labels have role = null.
 * Nesting is modelled via the parent self-reference (Gmail "Work/Invoices"
 * semantics; IMAP subfolder hierarchy maps onto the same tree).
 *
 * Name uniqueness per (account, parent) is enforced at the service layer
 * (find-or-create), not by a DB constraint — a partial/COALESCE unique
 * index cannot be expressed in Doctrine attributes and would cause
 * schema-diff drift.
 */
#[ORM\Entity(repositoryClass: LabelRepository::class)]
#[ORM\Table(name: 'label')]
#[ORM\Index(name: 'idx_label_account', columns: ['account_id'])]
#[ORM\Index(name: 'idx_label_gmail_label_id', columns: ['gmail_label_id'])]
class Label
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    public private(set) ?Account $account = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public private(set) ?self $parent = null;

    /**
     * @var Collection<int, Label>
     */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    public private(set) Collection $children;

    /**
     * Leaf name only ("Invoices", not "Work/Invoices"). Full paths are
     * derived from the parent chain via $fullName.
     */
    #[ORM\Column(length: 255)]
    public private(set) ?string $name = null;

    #[ORM\Column(length: 50, nullable: true, enumType: LabelRole::class)]
    public private(set) ?LabelRole $role = null;

    /**
     * Gmail API label id (e.g. "INBOX", "Label_123"). Null for labels on
     * plain IMAP accounts and for local-only labels not yet pushed.
     */
    #[ORM\Column(length: 255, nullable: true)]
    public private(set) ?string $gmailLabelId = null;

    #[ORM\Column(length: 512, nullable: true)]
    public ?string $graphFolderId = null {
        set (?string $value) {
            $this->graphFolderId = $value;
        }
    }

    /**
     * Optional UI color (Tailwind token or hex, decided in Phase 5).
     */
    #[ORM\Column(length: 30, nullable: true)]
    public private(set) ?string $color = null;

    #[ORM\Column(options: ['default' => true])]
    public private(set) bool $isVisible = true;

    /**
     * Fixed ordering for system labels; null for custom labels, which sort
     * alphabetically after the system block (Postgres sorts NULLS LAST on
     * ASC by default).
     */
    #[ORM\Column(nullable: true)]
    public private(set) ?int $sortOrder = null;

    #[ORM\Column]
    public private(set) ?DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    public private(set) ?DateTimeImmutable $updatedAt = null;

    /**
     * Gmail-style full path, e.g. "Work/Invoices".
     */
    public string $fullName {
        get {
            if (null === $this->parent) {
                return (string) $this->name;
            }

            return $this->parent->fullName . '/' . $this->name;
        }
    }

    public bool $isSystem {
        get {
            return null !== $this->role;
        }
    }

    public int $depth {
        get {
            if (null === $this->parent) {
                return 0;
            }

            return $this->parent->depth + 1;
        }
    }

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function setAccount(?Account $account): static
    {
        $this->account = $account;

        return $this;
    }

    public function setParent(?self $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    public function addChild(self $child): static
    {
        if (false === $this->children->contains($child)) {
            $this->children->add($child);
            $child->setParent($this);
        }

        return $this;
    }

    public function removeChild(self $child): static
    {
        if (true === $this->children->removeElement($child)) {
            if ($child->parent === $this) {
                $child->setParent(null);
            }
        }

        return $this;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function setRole(?LabelRole $role): static
    {
        $this->role = $role;

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

    public function setColor(?string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function setIsVisible(bool $isVisible): static
    {
        $this->isVisible = $isVisible;

        return $this;
    }

    public function setSortOrder(?int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function setUpdatedAt(DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
