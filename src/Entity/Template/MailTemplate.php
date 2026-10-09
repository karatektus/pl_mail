<?php

declare(strict_types=1);

namespace App\Entity\Template;

use App\Domain\Trait\TimestampableTrait;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Template\MailTemplateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A message written once and inserted into the composer whenever it is needed.
 *
 * User-scoped, like Label and MailRule. Where it sits in the tree is the same
 * pair of nullable columns TemplateFolder uses — see that class for the model —
 * with both null meaning the top level, where a template is offered whichever
 * account is being written from.
 *
 * THE BODY HOLDS ITS VARIABLES AS TEXT. A variable is the literal token
 * `{{recipient.first_name}}` or `{{date|offset=+7d|format=long}}` inside
 * otherwise ordinary HTML, not an element. Two things decided that:
 *
 *   - The body is sanitised with the same allow-list as inbound mail on its way
 *     in (it is injected into the composer's own document on its way out), and
 *     that allow-list drops every class and data- attribute. A variable stored
 *     as a marked element would not survive being saved.
 *   - The subject is a plain string and needs variables too. One notation that
 *     works in both is one parser.
 *
 * The chips the editor draws are the editor's rendering of those tokens and
 * never reach this column — see TemplateTokens for the grammar and
 * TemplateRenderer for what each one turns into.
 */
#[ORM\Entity(repositoryClass: MailTemplateRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'mail_template')]
// Every read is "this user's templates", all of them, for the tree or the picker.
#[ORM\Index(name: 'idx_mail_template_usr', columns: ['usr_id'])]
class MailTemplate
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public User $usr;

    /**
     * SET NULL, not CASCADE: removing a mail account moves its templates to the
     * top level rather than deleting them.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?Account $account = null;

    /** SET NULL for the same reason; TemplateLibrary moves them up explicitly first. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?TemplateFolder $folder = null;

    #[ORM\Column(length: 255)]
    public string $name = '';

    /**
     * Optional. Only ever fills a subject line that is still empty, so a
     * template can be dropped into a reply without renaming the conversation.
     */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $subject = null;

    /** Sanitised HTML with variable tokens in it. Never trusted on the way out either. */
    #[ORM\Column(type: Types::TEXT)]
    public string $body = '';
}
