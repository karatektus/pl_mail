<?php

declare(strict_types=1);

namespace App\Service\Rule;

use App\Domain\Filter\FilterVocabulary;
use App\Entity\Mail\Account;
use App\Entity\Rule\MailRule;
use App\Repository\Integration\IntegrationRepository;
use App\Repository\Label\LabelRepository;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Restates a rule in plain language: "If Subject contains invoice → Apply
 * label Receipts".
 *
 * Reading a filter back in words is how someone catches an AND that should
 * have been an OR — the tree looks equally correct either way. So the sentence
 * appears in two places, the rule list and live in the editor, and both come
 * from here.
 *
 * Deliberately server-side only. The editor used to build its own sentence in
 * JavaScript, which meant two implementations of "what this rule says" that
 * could drift apart — the same trap the filter engines fell into. The editor
 * now takes the sentence from the preview response it was already fetching for
 * the match count, so there is one describer and it is translated properly.
 */
final class FilterDescriber
{
    /** @var array<int, array<int,string>> userId => (labelId => full name) */
    private array $labelNames = [];

    /** @var array<int, array<int,string>> userId => (integrationId => name) */
    private array $integrationNames = [];

    /** Whose labels the sentence currently being built may name. */
    private ?UserInterface $subject = null;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly LabelRepository       $labelRepository,
        private readonly IntegrationRepository $integrationRepository,
    ) {}

    public function describeRule(MailRule $rule): string
    {
        return $this->describe($rule->conditions, $rule->actions, $rule->usr, $rule->account);
    }

    /**
     * The user is required, not inferred: label names are resolved for the
     * sentence, and resolving them globally would let one user's rule render
     * another user's label name.
     *
     * The account matters to the sentence, not just to the engine. A rule with
     * no conditions read "If this is any message → …" whatever it was scoped
     * to, which is the one case where the scope is the entire filter — the
     * sentence claimed the whole mailbox while the rule meant one account.
     *
     * @param array<string,mixed>       $conditions
     * @param list<array<string,mixed>> $actions
     */
    public function describe(
        array $conditions,
        array $actions,
        ?UserInterface $user,
        ?Account $account = null,
    ): string {
        $this->subject = $user;

        $when = $this->scope($this->node($conditions), $conditions, $account);
        $then = [];

        foreach ($actions as $action) {
            $described = $this->action($action);

            if (null !== $described) {
                $then[] = $described;
            }
        }

        if (0 === count($then)) {
            return $this->translator->trans('settings.filters.summary.no_actions', ['%conditions%' => $when]);
        }

        return $this->translator->trans('settings.filters.summary.full', [
            '%conditions%' => $when,
            '%actions%' => implode(', ', $then),
        ]);
    }

    /**
     * Adds "in <account>" to the condition half, where there is an account.
     *
     * Two phrasings rather than one, because with no conditions the account is
     * the whole subject of the sentence — "every message in x@example.com" —
     * while alongside conditions it is a clause narrowing them.
     *
     * @param array<string,mixed> $conditions
     */
    private function scope(string $when, array $conditions, ?Account $account): string
    {
        if (null === $account) {
            return $when;
        }

        $address = (string) ($account->email ?? $account->username ?? '');

        if ('' === $address) {
            return $when;
        }

        return $this->translator->trans(
            0 === count($conditions)
                ? 'settings.filters.summary.every_message_in'
                : 'settings.filters.summary.in_account',
            ['%conditions%' => $when, '%account%' => $address],
        );
    }

    /**
     * @param array<string,mixed> $node
     */
    private function node(array $node): string
    {
        // No conditions: the rule acts on everything it is scoped to. Said in
        // words rather than left blank, because "If → Apply label Receipts"
        // reads as a rule with a piece missing rather than a deliberate one.
        if (0 === count($node)) {
            return $this->translator->trans('settings.filters.summary.every_message');
        }

        if (true === array_key_exists('operator', $node)) {
            return $this->operator($node);
        }

        $parts = [];

        foreach ($node as $property => $value) {
            $parts[] = $this->condition((string) $property, $value);
        }

        // A condition object is an implicit AND of its properties.
        return implode(' ' . $this->translator->trans('settings.filters.join.and') . ' ', $parts);
    }

    /**
     * @param array<string,mixed> $node
     */
    private function operator(array $node): string
    {
        $conditions = $node['conditions'] ?? [];

        if (false === is_array($conditions)) {
            return '';
        }

        $parts = [];

        foreach ($conditions as $child) {
            if (true === is_array($child)) {
                $parts[] = $this->node($child);
            }
        }

        if ('NOT' === ($node['operator'] ?? null)) {
            // Spelled out, because NOT over several conditions means "none of"
            // and reads to most people as "not all of".
            return $this->translator->trans('settings.filters.join.none', [
                '%list%' => implode(' ' . $this->translator->trans('settings.filters.join.or') . ' ', $parts),
            ]);
        }

        $joiner = 'OR' === ($node['operator'] ?? null)
            ? $this->translator->trans('settings.filters.join.or')
            : $this->translator->trans('settings.filters.join.and');

        $joined = implode(' ' . $joiner . ' ', $parts);

        // Parenthesise only where it changes the reading — a single child needs
        // no brackets and adding them makes the sentence harder, not clearer.
        return count($parts) > 1 ? '(' . $joined . ')' : $joined;
    }

    private function condition(string $property, mixed $value): string
    {
        $label = $this->translator->trans('settings.filters.field.' . $property);

        if (true === in_array($property, ['hasLabel', 'notLabel'], true)) {
            return $label . ' ' . $this->labelName($value);
        }

        if (true === in_array($property, FilterVocabulary::KEYWORD_CONDITIONS, true)) {
            return $label . ' ' . $this->translator->trans(
                'settings.filters.keyword.' . ltrim((string) $value, '$'),
            );
        }

        if (true === in_array($property, FilterVocabulary::BOOL_CONDITIONS, true)) {
            return $label . ' ' . $this->translator->trans(
                true === $value ? 'settings.filters.yes' : 'settings.filters.no',
            );
        }

        return trim($label . ' ' . (is_scalar($value) ? (string) $value : ''));
    }

    /**
     * @param array<string,mixed> $action
     */
    private function action(array $action): ?string
    {
        $type = (string) ($action['type'] ?? '');

        if ('' === $type) {
            return null;
        }

        $described = $this->translator->trans('settings.filters.action.' . $type);

        if (true === array_key_exists('labelId', $action)) {
            return $described . ' ' . $this->labelName($action['labelId']);
        }

        if (true === array_key_exists('integrationId', $action)) {
            return $described . ' ' . $this->integrationName($action['integrationId']);
        }

        return $described;
    }

    /**
     * Named the same way labels are, and missing the same way: a connection
     * disconnected out from under a rule reads as missing rather than as a
     * bare id.
     */
    private function integrationName(mixed $id): string
    {
        if (null === $this->subject || false === is_int($id)) {
            return $this->translator->trans('settings.filters.integration_missing');
        }

        $userId = (int) $this->subject->id;

        if (false === array_key_exists($userId, $this->integrationNames)) {
            $map = [];

            foreach ($this->integrationRepository->findForUserOrdered($this->subject) as $integration) {
                $map[(int) $integration->id] = $integration->name;
            }

            $this->integrationNames[$userId] = $map;
        }

        return $this->integrationNames[$userId][$id]
            ?? $this->translator->trans('settings.filters.integration_missing');
    }

    private function labelName(mixed $id): string
    {
        $missing = $this->translator->trans('settings.filters.label_missing');

        if (null === $this->subject) {
            return $missing;
        }

        $userId = (int) $this->subject->id;

        if (false === array_key_exists($userId, $this->labelNames)) {
            $map = [];

            foreach ($this->labelRepository->findForUser($this->subject) as $label) {
                $map[(int) $label->id] = (string) $label->fullName;
            }

            $this->labelNames[$userId] = $map;
        }

        // A label deleted out from under a rule: name it as missing rather than
        // rendering a bare id, which would read as nonsense.
        return $this->labelNames[$userId][(int) $id] ?? $missing;
    }
}
