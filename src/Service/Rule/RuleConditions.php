<?php

declare(strict_types=1);

namespace App\Service\Rule;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Rule\MailRule;
use App\Jmap\Query\CompiledFilter;
use App\Jmap\Query\EmailFilterCompiler;
use App\Repository\Label\LabelRepository;

/**
 * What a filter matches, as SQL: its conditions, and — unless it says
 * otherwise — nothing in Spam.
 *
 * A filter is the last word on where mail is listed (RuleCategoryFiler), and
 * Spam is where forged mail ends up. A "Shipping" filter on "subject contains
 * parcel" would otherwise lift a fake delivery notice out of Spam's company
 * and into a tab next to the real ones, and "Apply to existing mail" would do
 * it to every one Spam has ever caught. So Spam is left out of every filter by
 * default: on arrival (MailRuleEngine), in "Apply to existing mail"
 * (ApplyMailRuleHandler) and in "Test my filters" (RuleMessageCheck), which all
 * ask here so that the three cannot disagree.
 *
 * A filter that NAMES Spam — "Has label: Spam" in its conditions — is about
 * Spam, and gets it: that is how a reader writes "label whatever lands in Spam
 * from this sender". Named under a NOT it is the opposite ("is not in Spam"),
 * which the default already does.
 *
 * Trash is not left out. Mail there is mail the reader has finished with, not
 * mail somebody else sent to deceive them, and a filter that labels it does no
 * harm the reader did not ask for.
 */
final readonly class RuleConditions
{
    public function __construct(
        private EmailFilterCompiler $compiler,
        private LabelRepository     $labels,
    ) {}

    /**
     * @throws \Throwable when the stored conditions no longer compile — callers
     *                    already treat that as "this rule is broken"
     */
    public function compile(MailRule $rule): CompiledFilter
    {
        $filter = $this->compiler->compile($rule->conditions);

        if (false === $this->skipsSpam($rule)) {
            return $filter;
        }

        return new CompiledFilter(
            sprintf(
                "(%s) AND NOT EXISTS (SELECT 1 FROM message_label ml_spam
                                      JOIN label l_spam ON l_spam.id = ml_spam.label_id
                                      WHERE ml_spam.message_id = m.id AND l_spam.role = '%s')",
                $filter->sql,
                LabelRole::Spam->value,
            ),
            $filter->parameters,
        );
    }

    /** Whether this filter leaves mail in Spam alone — every one that does not name it. */
    public function skipsSpam(MailRule $rule): bool
    {
        $ids = $this->namedLabels($rule->conditions, negated: false);

        if ([] === $ids) {
            return true;
        }

        foreach ($this->labels->findBy(['id' => $ids]) as $label) {
            if (LabelRole::Spam === $label->role && $label->usr === $rule->usr) {
                return false;
            }
        }

        return true;
    }

    /**
     * The label ids a filter asks to be present, outside any NOT.
     *
     * @param array<array-key,mixed> $node
     *
     * @return list<int>
     */
    private function namedLabels(array $node, bool $negated): array
    {
        if (true === is_array($node['conditions'] ?? null)) {
            $negated = $negated !== ('NOT' === ($node['operator'] ?? null));
            $ids     = [];

            foreach ($node['conditions'] as $child) {
                if (true === is_array($child)) {
                    $ids = [...$ids, ...$this->namedLabels($child, $negated)];
                }
            }

            return $ids;
        }

        $id = $node['hasLabel'] ?? null;

        return false === $negated && (is_int($id) || (is_string($id) && ctype_digit($id))) ? [(int) $id] : [];
    }
}
