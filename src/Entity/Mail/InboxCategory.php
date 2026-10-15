<?php

declare(strict_types=1);

namespace App\Entity\Mail;

use App\Domain\Trait\TimestampableTrait;
use App\Entity\User\User;
use App\Repository\Mail\InboxCategoryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * An inbox tab somebody made themselves.
 *
 * The five Gmail categories are an enum and stay one: they are what a provider
 * says, what the assistant is asked to choose between, and the closed
 * vocabulary JMAP clients are documented to draw their tabs from. This is the
 * other kind — a name the reader picked, filled by a filter's "File in
 * category" action, and shown beside the built-in tabs while it holds mail.
 *
 * A CATEGORY BELONGS TO THE USER, NOT TO AN ACCOUNT, for the reason Label does:
 * a person's "Shipping" is one tab across every mailbox they own.
 *
 * It is referenced by id from two places — Message::$ruleCategory and
 * MessageThread::$userCategory — and both references are ON DELETE SET NULL, so
 * the database un-files the mail when a category goes and nothing is left
 * pointing at a row that no longer exists. Pins are the one thing that cannot
 * be left to a foreign key; see InboxCategoryManager::remove().
 *
 * Name uniqueness per user is the service layer's job (case-insensitively), not
 * a constraint: an expression index cannot be written in Doctrine attributes
 * and would drift from the schema diff, the same trade Label documents.
 */
#[ORM\Entity(repositoryClass: InboxCategoryRepository::class)]
#[ORM\Table(name: 'inbox_category')]
// The only lookup is "this user's categories" — on every inbox render and every
// filter edit. Their order is not here: it is one list across the built-in tabs
// and these, so it lives with the other sorting choices (CategorySorting).
#[ORM\Index(name: 'idx_inbox_category_usr', columns: ['usr_id'])]
#[ORM\HasLifecycleCallbacks]
class InboxCategory
{
    use TimestampableTrait;

    public const int NAME_MAX_LENGTH = 40;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public User $usr;

    #[ORM\Column(length: self::NAME_MAX_LENGTH)]
    public string $name;

    /** The key this category's tab goes by — see InboxTab. */
    public function tabKey(): string
    {
        return \App\Domain\DTO\Mail\InboxTab::CUSTOM_PREFIX . (int) $this->id;
    }
}
