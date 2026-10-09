<?php

declare(strict_types=1);

namespace App\Entity\Template;

use App\Domain\Trait\TimestampableTrait;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Template\TemplateFolderRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A folder the user made to sort templates into.
 *
 * WHAT IS NOT A ROW. The tree in Settings → Templates opens with one folder
 * per mail account, and those are not stored: an account folder is the Account
 * itself, drawn as a folder. That is what makes "the tree mirrors your mail
 * accounts" true without any code keeping the two in step — there is nothing to
 * create when an account is added, nothing to rename when its address changes
 * and nothing left behind when it goes. Stored account folders would need all
 * three, and each is a place for the mirror to crack.
 *
 * So a folder's place in the tree is two nullable columns read together:
 *
 *   account null, parent null   a folder at the top level, beside the accounts
 *   account set,  parent null   a folder directly inside an account
 *   parent set                  a folder inside another, and `account` then
 *                               repeats the parent's — see below
 *
 * `account` is repeated on a nested folder rather than derived by walking up,
 * because the database has to act on it: deleting an account deletes every
 * folder under it in one cascade, at any depth, and a foreign key cannot follow
 * a chain of parents. TemplateLibrary is the only writer and keeps the two in
 * agreement.
 *
 * WHAT HAPPENS TO THE TEMPLATES INSIDE is deliberately the opposite of what
 * happens to the folder. Folders cascade away; MailTemplate's own foreign keys
 * are SET NULL, so a template whose folder or account disappears lands at the
 * top level instead of being deleted with it. Somebody removing a mail account
 * has not asked to lose what they wrote.
 */
#[ORM\Entity(repositoryClass: TemplateFolderRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'template_folder')]
// Every read is "this user's folders", all of them at once, to build the tree.
#[ORM\Index(name: 'idx_template_folder_usr', columns: ['usr_id'])]
class TemplateFolder
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public User $usr;

    /** The account folder this one sits under, at any depth. Null at the top level. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public ?Account $account = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public ?TemplateFolder $parent = null;

    #[ORM\Column(length: 255)]
    public string $name = '';
}
