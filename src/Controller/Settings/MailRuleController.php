<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Domain\Enum\Integration\Capability;
use App\Domain\Enum\Mail\RuleRunState;
use App\Domain\Filter\FilterAstValidator;
use App\Domain\Filter\InvalidFilterException;
use App\Entity\Rule\MailRule;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\ApplyMailRuleMessage;
use App\Jmap\Query\EmailFilterCompiler;
use App\Repository\Integration\IntegrationRepository;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\Rule\MailRuleRepository;
use App\Security\Voter\OwnershipVoter;
use App\Service\Rule\FilterDescriber;
use App\Service\Rule\RuleActionExecutor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Mail rules, in settings.
 *
 * The condition tree arrives as JSON in a hidden field rather than through a
 * Symfony form: the shape is recursive and its depth is decided by the person
 * building it, which a form tree models badly. FilterAstValidator is what makes
 * that safe — the JSON is validated against the rule vocabulary before it is
 * stored, and it feeds a SQL compiler, so it is never trusted as submitted.
 *
 * The editor renders into its own Turbo Frame beside the list, so opening a
 * rule does not lose the list's scroll position.
 */
#[Route('/settings/filters', name: 'app_settings_filters_')]
#[IsGranted('IS_AUTHENTICATED')]
final class MailRuleController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly MailRuleRepository     $ruleRepository,
        private readonly MessageRepository      $messageRepository,
        private readonly LabelRepository        $labelRepository,
        private readonly IntegrationRepository  $integrationRepository,
        private readonly AccountRepository      $accountRepository,
        private readonly FilterAstValidator     $validator,
        private readonly EmailFilterCompiler    $compiler,
        private readonly RuleActionExecutor     $executor,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface    $bus,
        private readonly FilterDescriber        $describer,
    ) {}

    #[Route('/new', name: 'new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->editor(new MailRule());
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET'])]
    public function edit(MailRule $rule): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $rule);

        return $this->editor($rule);
    }

    /**
     * The rule list on its own, for the frame's src and for reload() while a
     * run is walking the mailbox.
     */
    #[Route('/list', name: 'list', methods: ['GET'])]
    public function list(): Response
    {
        return $this->render('settings/filters/_list_frame.html.twig', [
            'rules' => $this->ruleRepository->findForUserOrdered($this->getUser()),
            'labels' => $this->labelRepository->findForUserTreeOrdered($this->getUser()),
        ]);
    }

    /**
     * Rewrite execution order.
     *
     * Order is not cosmetic here the way account order is: combined with
     * stopProcessing it decides which rule wins, so this is CSRF-checked like
     * every other mutation on this page.
     */
    #[Route('/reorder', name: 'reorder', methods: ['POST'])]
    public function reorder(Request $request): JsonResponse
    {
        if (false === $this->isCsrfTokenValid('mail_rule_reorder', (string) $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $ids = $request->toArray()['ids'] ?? null;

        if (false === is_array($ids)) {
            return $this->json(['ok' => false], Response::HTTP_BAD_REQUEST);
        }

        $this->ruleRepository->applyOrder(
            $this->getUser(),
            array_values(array_filter(array_map('intval', $ids))),
        );
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    /** Closes the editor frame without saving. */
    #[Route('/cancel', name: 'cancel', methods: ['GET'])]
    public function cancel(): Response
    {
        return $this->render('settings/filters/_editor_empty.html.twig');
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->assertCsrf($request, 'mail_rule_save');

        $id = (string) $request->request->get('id', '');
        $rule = '' === $id ? new MailRule() : $this->ruleRepository->find((int) $id);

        if (null === $rule) {
            throw $this->createNotFoundException();
        }

        if (null !== $rule->id) {
            $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $rule);
        }

        $name = trim((string) $request->request->get('name', ''));
        $conditions = $this->decode($request->request->get('conditions'));
        $actions = $this->decode($request->request->get('actions'));

        $errors = [];

        if ('' === $name) {
            $errors[] = 'settings.filters.error.name_required';
        }

        try {
            $this->validator->validate($conditions);
        } catch (InvalidFilterException $e) {
            $errors[] = $e->getMessage();
        }

        $actions = $this->sanitizeActions($actions);

        if (0 === count($actions)) {
            $errors[] = 'settings.filters.error.action_required';
        }

        if (count($errors) > 0) {
            // 422 so Turbo renders the response instead of treating it as a
            // successful navigation — the same reason LabelController does it.
            return $this->editor($rule, $errors, $request, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $rule->usr = $this->getUser();
        $rule->name = $name;
        $rule->account = $this->resolveAccount($request->request->get('account'));
        $rule->conditions = $conditions;
        $rule->actions = $actions;
        $rule->isEnabled = '1' === (string) $request->request->get('isEnabled', '1');
        $rule->stopProcessing = '1' === (string) $request->request->get('stopProcessing', '0');

        if (null === $rule->id) {
            $rule->sortOrder = $this->ruleRepository->nextSortOrder($this->getUser());
            $this->em->persist($rule);
        }

        $this->em->flush();

        return $this->listStream('settings.filters.saved');
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, MailRule $rule): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $rule);
        $this->assertCsrf($request, 'mail_rule_delete' . $rule->id);

        $this->em->remove($rule);
        $this->em->flush();

        return $this->listStream('settings.filters.deleted');
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(Request $request, MailRule $rule): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $rule);
        $this->assertCsrf($request, 'mail_rule_toggle' . $rule->id);

        $rule->isEnabled = false === $rule->isEnabled;
        $this->em->flush();

        return $this->listStream(null);
    }

    /**
     * Live "how many messages would this catch" while the rule is being
     * written. Answering as the author types is what turns a filter from
     * something you write and hope about into something you can see.
     */
    #[Route('/preview', name: 'preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $payload = $request->toArray();
        $conditions = $payload['conditions'] ?? null;

        if (false === is_array($conditions)) {
            return $this->json(['ok' => false, 'error' => 'Invalid conditions.']);
        }

        // Read once and used twice: the count and the sentence under it have
        // to be describing the same rule.
        $account = $this->resolveAccount($payload['account'] ?? null);

        try {
            $this->validator->validate($conditions);
            // Scoped to the account the rule is being written for: it only
            // ever acts there, and counting every account would be most wrong
            // exactly where it matters most — a rule with no conditions.
            $result = $this->messageRepository->countMatchingForUser(
                $this->getUser(),
                $this->compiler->compile($conditions),
                account: $account,
            );
        } catch (InvalidFilterException $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable) {
            return $this->json(['ok' => false, 'error' => 'That filter could not be evaluated.']);
        }

        return $this->json([
            'ok' => true,
            'count' => $result['count'],
            'capped' => $result['capped'],
            // Described here rather than in the browser: one implementation of
            // "what this rule says", properly translated.
            'description' => $this->describer->describe(
                $conditions,
                is_array($payload['actions'] ?? null) ? $payload['actions'] : [],
                $this->getUser(),
                // The same account the count is scoped to, so the sentence and
                // the number under it cannot describe different rules.
                $account,
            ),
        ]);
    }

    /**
     * Apply an existing rule to mail that arrived before it did.
     *
     * Queued, not run here: this has to reach every matching message, which
     * over a real mailbox is far more than a request should attempt. The rule
     * row carries the progress so the answer to "is it done?" survives a
     * reload — see ApplyMailRuleHandler.
     */
    #[Route('/{id}/run', name: 'run', methods: ['POST'])]
    public function run(Request $request, MailRule $rule): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $rule);
        $this->assertCsrf($request, 'mail_rule_run' . $rule->id);

        // Re-running while a run is in flight would double-count progress and
        // race the handler's writes.
        if (true === $rule->runState->isBusy()) {
            return $this->listStream('settings.filters.already_running');
        }

        $rule->runState = RuleRunState::Queued;
        $rule->runProcessed = 0;
        $rule->runStartedAt = null;
        $rule->runFinishedAt = null;
        $this->em->flush();

        $this->bus->dispatch(new ApplyMailRuleMessage((int) $rule->id));

        return $this->listStream('settings.filters.queued');
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @param list<string> $errors
     */
    private function editor(MailRule $rule, array $errors = [], ?Request $request = null, int $status = Response::HTTP_OK): Response
    {
        return $this->render('settings/filters/_editor.html.twig', [
            'rule' => $rule,
            'errors' => $errors,
            // On a rejected save, re-render what was typed rather than what is
            // stored — losing a half-built tree to a missing name is the
            // fastest way to make someone stop trusting the editor.
            'submittedName' => $request?->request->get('name'),
            'submittedConditions' => $request?->request->get('conditions'),
            'submittedActions' => $request?->request->get('actions'),
            'labels' => $this->labelRepository->findForUserTreeOrdered($this->getUser()),
            // The user's own arrangement, as everywhere else mail accounts are
            // offered to pick from — see findActiveForUserOrdered().
            'accounts' => $this->accountRepository->findForUserOrdered($this->getUser()),
            'actionTypes' => RuleActionExecutor::TYPES,
            // Upload-capable only: a rule cannot save an attachment to a
            // service that can only be read from.
            'integrations' => $this->integrationRepository->findSupportingForUser(
                $this->getUser(),
                Capability::Upload,
            ),
        ], new Response(status: $status));
    }

    private function listStream(?string $toastMessage): Response
    {
        return $this->render('settings/filters/_saved.stream.html.twig', [
            'toastMessage' => $toastMessage,
            'rules' => $this->ruleRepository->findForUserOrdered($this->getUser()),
            'labels' => $this->labelRepository->findForUserTreeOrdered($this->getUser()),
        ], new Response(headers: ['Content-Type' => 'text/vnd.turbo-stream.html']));
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(mixed $raw): array
    {
        if (false === is_string($raw) || '' === $raw) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return true === is_array($decoded) ? $decoded : [];
    }

    /**
     * Keep only well-formed actions of a known type. Unlike conditions — which
     * are rejected loudly, because a dropped condition silently widens a rule —
     * a malformed action can only ever be a client bug, and dropping it is the
     * conservative outcome.
     *
     * @param array<string,mixed> $actions
     *
     * @return list<array<string,mixed>>
     */
    private function sanitizeActions(array $actions): array
    {
        $clean = [];

        foreach ($actions as $action) {
            if (false === is_array($action)) {
                continue;
            }

            $type = (string) ($action['type'] ?? '');

            if (false === in_array($type, RuleActionExecutor::TYPES, true)) {
                continue;
            }

            $entry = ['type' => $type];

            if (true === in_array($type, [RuleActionExecutor::APPLY_LABEL, RuleActionExecutor::REMOVE_LABEL], true)) {
                $labelId = $action['labelId'] ?? null;

                if (false === is_int($labelId)) {
                    continue;
                }

                $label = $this->labelRepository->find($labelId);

                if (null === $label || false === $this->isGranted(OwnershipVoter::OWN, $label)) {
                    continue;
                }

                $entry['labelId'] = $labelId;
            }

            if (RuleActionExecutor::SAVE_TO_INTEGRATION === $type) {
                $integrationId = $action['integrationId'] ?? null;

                if (false === is_int($integrationId)) {
                    continue;
                }

                // Same ownership check as labels — an action is user input, and
                // an id from another user's account must not be storable even
                // though the executor re-checks it again at run time.
                $integration = $this->integrationRepository->find($integrationId);

                if (null === $integration || false === $this->isGranted(OwnershipVoter::OWN, $integration)) {
                    continue;
                }

                $entry['integrationId'] = $integrationId;

                $folder = $action['folder'] ?? null;

                if (true === is_string($folder) && '' !== trim($folder)) {
                    $entry['folder'] = trim($folder);
                }
            }

            $clean[] = $entry;
        }

        return $clean;
    }

    private function resolveAccount(mixed $id): ?\App\Entity\Mail\Account
    {
        if (false === is_string($id) || '' === $id) {
            return null;
        }

        $account = $this->accountRepository->find((int) $id);

        if (null === $account || false === $this->isGranted(OwnershipVoter::OWN, $account)) {
            return null;
        }

        return $account;
    }

}
