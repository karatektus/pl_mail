<?php

declare(strict_types=1);

namespace App\Service\Rule;

use App\Domain\DTO\Mail\SpamSender;
use App\Domain\Filter\FilterAstValidator;
use App\Entity\Rule\MailRule;
use App\Entity\User\User;
use App\Repository\Rule\MailRuleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Makes the filter the spam button offers: everything from this sender, or
 * from this sender's whole domain, goes to Spam from now on.
 *
 * An ordinary rule, the same one the editor under Settings → Filters would
 * have saved — one sender condition and the "mark as spam" action — so it is
 * listed there, and can be edited, switched off or deleted like any other.
 * Nothing about it is special once it exists.
 *
 * It matches on `fromAddress` or `fromDomain`, never on `from`. `from` is a
 * substring over address and display name: a filter for `a@x.de` built on it
 * would also file `ba@x.de`, and anybody could land in it by putting the
 * address in their display name. The two sender conditions compare the whole
 * address, or the whole domain, and nothing else.
 */
final readonly class SpamFilterCreator
{
    public const string SENDER = 'sender';
    public const string DOMAIN = 'domain';

    /** What the button's menu may ask for. */
    public const array SCOPES = [self::SENDER, self::DOMAIN];

    public function __construct(
        private MailRuleRepository     $rules,
        private FilterAstValidator     $validator,
        private EntityManagerInterface $em,
        private TranslatorInterface    $translator,
    ) {
    }

    /** Who a filter of this scope is about, as the toast and the rule's name say it. */
    public function pattern(SpamSender $sender, string $scope): string
    {
        return self::DOMAIN === $scope ? $sender->domainPattern() : $sender->address;
    }

    /**
     * The one condition such a filter has.
     *
     * @return array<string, string>
     */
    private function condition(SpamSender $sender, string $scope): array
    {
        return self::DOMAIN === $scope
            ? ['fromDomain' => $sender->domain]
            : ['fromAddress' => $sender->address];
    }

    /**
     * The rule, and whether this call made it.
     *
     * A second press on mail from the same sender finds the first one's rule
     * and makes nothing: two identical filters do nothing one does not, and
     * the list under Settings → Filters would grow a row per press. The flag
     * is what an Undo reads — it takes back a rule this press made, and leaves
     * alone one that was already there.
     *
     * @return array{0: MailRule, 1: bool}
     */
    public function create(User $user, SpamSender $sender, string $scope): array
    {
        $pattern    = $this->pattern($sender, $scope);
        $conditions = ['operator' => 'AND', 'conditions' => [$this->condition($sender, $scope)]];
        $actions    = [['type' => RuleActionExecutor::MARK_SPAM]];

        foreach ($this->rules->findForUserOrdered($user) as $existing) {
            if ($existing->conditions == $conditions && $existing->actions == $actions && null === $existing->account) {
                return [$existing, false];
            }
        }

        // Built here from two checked strings, so this cannot fail today. It
        // is still the gate every stored condition goes through, because the
        // tree feeds a SQL compiler.
        $this->validator->validate($conditions);

        $rule             = new MailRule();
        $rule->usr        = $user;
        $rule->name       = $this->translator->trans('settings.filters.spam_rule_name', ['%sender%' => $pattern]);
        $rule->conditions = $conditions;
        $rule->actions    = $actions;
        $rule->sortOrder  = $this->rules->nextSortOrder($user);

        $this->em->persist($rule);
        $this->em->flush();

        return [$rule, true];
    }
}
