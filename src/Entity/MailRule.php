<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Trait\TimestampableTrait;
use App\Repository\MailRuleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A Gmail-style filter: match incoming mail, then act on it.
 *
 * User-scoped, like Label. An optional account narrows a rule to one mailbox;
 * null means every account. Because labels are user-scoped too, a rule that
 * applies a label needs no per-account resolution — that ambiguity disappeared
 * with LabelBinding.
 *
 * Conditions are stored in the same AST the JMAP Email/query filter uses, so
 * ONE stored rule has two execution modes that cannot disagree:
 *   - EmailFilterEvaluator  → boolean against a hydrated Message (incoming)
 *   - EmailFilterCompiler   → SQL (apply the rule to existing mail)
 * FilterVocabulary defines the subset both provably implement identically.
 *
 * jsonb rather than json (the default elsewhere in this app) so the conditions
 * can be queried and indexed later without a cast — the precedent is
 * Account::$settings.
 */
#[ORM\Entity(repositoryClass: MailRuleRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'mail_rule')]
#[ORM\Index(name: 'idx_mail_rule_usr', columns: ['usr_id'])]
class MailRule
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?User $usr = null;

    #[ORM\Column(length: 255)]
    public ?string $name = null;

    /**
     * Null means "every account". Set, and the engine skips messages belonging
     * to any other account.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public ?Account $account = null;

    /**
     * A FilterOperator/FilterCondition tree. Validated by FilterAstValidator
     * before it is ever stored — it feeds a SQL compiler, so the shape is
     * never trusted from the client.
     *
     * @var array<string,mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    public array $conditions = [];

    /**
     * @var list<array<string,mixed>> list of {type, ...}
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    public array $actions = [];

    #[ORM\Column(options: ['default' => true])]
    public bool $isEnabled = true;

    /** Rules run in this order; ties break on id. */
    #[ORM\Column(options: ['default' => 0])]
    public int $sortOrder = 0;

    /** Stop evaluating further rules once this one has matched. */
    #[ORM\Column(options: ['default' => false])]
    public bool $stopProcessing = false;

    /** True when this rule should be considered for a message of $account. */
    public function appliesTo(Account $account): bool
    {
        return null === $this->account || $this->account === $account;
    }
}
