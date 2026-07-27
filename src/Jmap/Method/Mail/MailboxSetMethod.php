<?php

declare(strict_types=1);

namespace App\Jmap\Method\Mail;

use App\Entity\Account;
use App\Entity\Label;
use App\Jmap\Account\AccountResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\LabelRepository;
use App\Service\Label\LabelStructurePropagator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Mailbox/set" (RFC 8621 §2.5) — create, rename, re-parent and destroy
 * Mailboxes, which are plMail Labels.
 *
 * Provider mirroring goes through LabelStructurePropagator, the same seam the
 * web LabelController uses, and is opt-in per account. With the toggle off this
 * is a purely local operation, which is what plMail did before.
 *
 * System labels (role !== null) are immutable: they map onto provider built-ins
 * like INBOX and SENT that cannot be renamed or deleted through any API, and
 * Mailbox/get already advertises that via myRights.mayRename/mayDelete.
 */
final class MailboxSetMethod implements JmapMethod
{
    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly LabelRepository $labelRepository,
        private readonly LabelStructurePropagator $propagator,
        private readonly StateManager $stateManager,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function name(): string
    {
        return 'Mailbox/set';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);
        $accountId = $account->getId();

        $oldState = $this->stateManager->stateFor($accountId, JmapObjectType::Mailbox);
        $ifInState = $arguments['ifInState'] ?? null;

        if (null !== $ifInState && $ifInState !== $oldState) {
            throw new MethodException('stateMismatch', 'The account has changed since ifInState was issued.');
        }

        $created = [];
        $notCreated = [];
        $updated = [];
        $notUpdated = [];
        $destroyed = [];
        $notDestroyed = [];

        $this->applyCreates($account, $arguments['create'] ?? null, $context, $created, $notCreated);
        $this->applyUpdates($account, $arguments['update'] ?? null, $context, $updated, $notUpdated);
        $this->applyDestroys(
            $account,
            $arguments['destroy'] ?? null,
            true === ($arguments['onDestroyRemoveEmails'] ?? false),
            $context,
            $destroyed,
            $notDestroyed,
        );

        $this->entityManager->flush();

        return [
            'accountId' => (string) $accountId,
            'oldState' => $oldState,
            'newState' => $this->stateManager->stateFor($accountId, JmapObjectType::Mailbox),
            'created' => 0 === count($created) ? new \stdClass() : $created,
            'notCreated' => 0 === count($notCreated) ? new \stdClass() : $notCreated,
            'updated' => 0 === count($updated) ? new \stdClass() : $updated,
            'notUpdated' => 0 === count($notUpdated) ? new \stdClass() : $notUpdated,
            'destroyed' => array_values($destroyed),
            'notDestroyed' => 0 === count($notDestroyed) ? new \stdClass() : $notDestroyed,
        ];
    }

    /**
     * @param array<string,mixed> $created
     * @param array<string,mixed> $notCreated
     */
    private function applyCreates(
        Account $account,
        mixed $create,
        JmapContext $context,
        array &$created,
        array &$notCreated,
    ): void {
        if (null === $create) {
            return;
        }

        if (false === is_array($create)) {
            throw new MethodException('invalidArguments', '"create" must be an object.');
        }

        foreach ($create as $creationId => $properties) {
            $creationId = (string) $creationId;

            if (false === is_array($properties)) {
                $notCreated[$creationId] = ['type' => 'invalidProperties', 'description' => 'Each create must be an object.'];
                continue;
            }

            try {
                $name = $this->requireName($properties['name'] ?? null);
                $parent = $this->resolveParent($account, $properties['parentId'] ?? null, $context);
                $this->assertNameFree($account, $parent, $name);
            } catch (MethodException $exception) {
                $notCreated[$creationId] = $exception->toError();
                continue;
            }

            $label = new Label()
                ->setAccount($account)
                ->setParent($parent)
                ->setName($name)
                ->setIsVisible(true !== ($properties['isSubscribed'] ?? true) ? false : true);

            if (true === is_int($properties['sortOrder'] ?? null)) {
                $label->setSortOrder($properties['sortOrder']);
            }

            $this->entityManager->persist($label);
            // The id is needed for the response and the propagator, and the
            // Mailbox state token must move before this method returns.
            $this->entityManager->flush();

            $this->propagator->created($label);
            $this->stateManager->recordCreated($account->getId(), JmapObjectType::Mailbox, (string) $label->id);
            $context->recordCreatedId($creationId, (string) $label->id);

            $created[$creationId] = [
                'id' => (string) $label->id,
                'sortOrder' => $label->sortOrder ?? 0,
                'role' => null,
                'totalEmails' => 0,
                'unreadEmails' => 0,
                'totalThreads' => 0,
                'unreadThreads' => 0,
            ];
        }
    }

    /**
     * @param array<string,mixed> $updated
     * @param array<string,mixed> $notUpdated
     */
    private function applyUpdates(
        Account $account,
        mixed $update,
        JmapContext $context,
        array &$updated,
        array &$notUpdated,
    ): void {
        if (null === $update) {
            return;
        }

        if (false === is_array($update)) {
            throw new MethodException('invalidArguments', '"update" must be an object.');
        }

        foreach ($update as $id => $patch) {
            $id = (string) $id;

            if (false === is_array($patch)) {
                $notUpdated[$id] = ['type' => 'invalidPatch', 'description' => 'Each update must be an object.'];
                continue;
            }

            $label = $this->findLabel($account, $context->resolveId($id) ?? $id);

            if (null === $label) {
                $notUpdated[$id] = ['type' => 'notFound', 'description' => 'No such Mailbox in this account.'];
                continue;
            }

            try {
                $renamed = $this->applyPatch($account, $label, $patch, $context);
            } catch (MethodException $exception) {
                $notUpdated[$id] = $exception->toError();
                continue;
            }

            if (true === $renamed) {
                $this->propagator->renamed($label);
            }

            $this->stateManager->recordUpdated($account->getId(), JmapObjectType::Mailbox, (string) $label->id);
            $updated[$id] = null;
        }
    }

    /**
     * @param array<string,mixed> $patch
     *
     * @return bool whether the change is one the provider needs to hear about
     */
    private function applyPatch(Account $account, Label $label, array $patch, JmapContext $context): bool
    {
        $structural = false;

        foreach ($patch as $property => $value) {
            switch ((string) $property) {
                case 'name':
                    $this->assertMutable($label);
                    $name = $this->requireName($value);
                    $this->assertNameFree($account, $label->parent, $name, $label);
                    $label->setName($name);
                    $structural = true;
                    break;

                case 'parentId':
                    $this->assertMutable($label);
                    $parent = $this->resolveParent($account, $value, $context);
                    $this->assertNoCycle($label, $parent);
                    $this->assertNameFree($account, $parent, (string) $label->name, $label);
                    $label->setParent($parent);
                    $structural = true;
                    break;

                case 'isSubscribed':
                    if (false === is_bool($value)) {
                        throw new MethodException('invalidProperties', '"isSubscribed" must be a boolean.');
                    }

                    $label->setIsVisible($value);
                    break;

                case 'sortOrder':
                    if (false === is_int($value)) {
                        throw new MethodException('invalidProperties', '"sortOrder" must be an integer.');
                    }

                    $label->setSortOrder($value);
                    break;

                default:
                    throw new MethodException('invalidPatch', sprintf('Property "%s" cannot be updated.', $property));
            }
        }

        return $structural;
    }

    /**
     * @param list<string>        $destroyed
     * @param array<string,mixed> $notDestroyed
     */
    private function applyDestroys(
        Account $account,
        mixed $destroy,
        bool $removeEmails,
        JmapContext $context,
        array &$destroyed,
        array &$notDestroyed,
    ): void {
        if (null === $destroy) {
            return;
        }

        if (false === is_array($destroy)) {
            throw new MethodException('invalidArguments', '"destroy" must be an array of ids.');
        }

        foreach ($destroy as $id) {
            $id = (string) $id;
            $label = $this->findLabel($account, $context->resolveId($id) ?? $id);

            if (null === $label) {
                $notDestroyed[$id] = ['type' => 'notFound', 'description' => 'No such Mailbox in this account.'];
                continue;
            }

            if (true === $label->isSystem) {
                $notDestroyed[$id] = [
                    'type' => 'forbidden',
                    'description' => 'System mailboxes cannot be destroyed.',
                ];
                continue;
            }

            if (count($label->children) > 0) {
                $notDestroyed[$id] = [
                    'type' => 'mailboxHasChild',
                    'description' => 'Destroy the child mailboxes first.',
                ];
                continue;
            }

            // plMail never deletes mail as a side effect of removing a label —
            // detaching the label leaves every message in place. Honouring
            // onDestroyRemoveEmails would mean destroying mail the provider
            // still holds, so it is refused rather than silently ignored.
            if (true === $removeEmails) {
                $notDestroyed[$id] = [
                    'type' => 'forbidden',
                    'description' => 'onDestroyRemoveEmails is not supported; Emails are always kept.',
                ];
                continue;
            }

            // Dispatch before removal: the propagator reads the remote id and
            // name off the entity, and there is nothing to read afterwards.
            $this->propagator->deleted($label);
            $this->stateManager->recordDestroyed($account->getId(), JmapObjectType::Mailbox, (string) $label->id);

            $this->entityManager->remove($label);
            $destroyed[] = $id;
        }
    }

    private function requireName(mixed $name): string
    {
        if (false === is_string($name)) {
            throw new MethodException('invalidProperties', 'A string "name" is required.');
        }

        $name = trim($name);

        if ('' === $name) {
            throw new MethodException('invalidProperties', '"name" cannot be empty.');
        }

        // Gmail encodes hierarchy in the name, so a slash in a leaf name would
        // silently create a nested label on the provider.
        if (true === str_contains($name, '/')) {
            throw new MethodException('invalidProperties', '"name" cannot contain "/"; use parentId for nesting.');
        }

        return $name;
    }

    private function resolveParent(Account $account, mixed $parentId, JmapContext $context): ?Label
    {
        if (null === $parentId) {
            return null;
        }

        if (false === is_string($parentId)) {
            throw new MethodException('invalidProperties', '"parentId" must be a Mailbox id or null.');
        }

        $parent = $this->findLabel($account, $context->resolveId($parentId) ?? $parentId);

        if (null === $parent) {
            throw new MethodException('invalidProperties', sprintf('No such parent Mailbox "%s".', $parentId));
        }

        return $parent;
    }

    private function assertMutable(Label $label): void
    {
        if (true === $label->isSystem) {
            throw new MethodException('forbidden', 'System mailboxes cannot be renamed or moved.');
        }
    }

    /**
     * Siblings must have distinct names — the same rule LabelResolver relies on
     * when it does find-or-create by (parent, name).
     */
    private function assertNameFree(Account $account, ?Label $parent, string $name, ?Label $ignore = null): void
    {
        $existing = $this->labelRepository->findOneChildByName($account, $parent, $name);

        if (null === $existing) {
            return;
        }

        if (null !== $ignore && $existing->id === $ignore->id) {
            return;
        }

        throw new MethodException('invalidProperties', sprintf('A mailbox named "%s" already exists here.', $name));
    }

    /**
     * A mailbox cannot become its own ancestor — that would orphan the subtree
     * from the root and make the fullName accessor recurse forever.
     */
    private function assertNoCycle(Label $label, ?Label $parent): void
    {
        $cursor = $parent;

        while (null !== $cursor) {
            if ($cursor->id === $label->id) {
                throw new MethodException('invalidProperties', 'A mailbox cannot be moved inside itself.');
            }

            $cursor = $cursor->parent;
        }
    }

    private function findLabel(Account $account, string $id): ?Label
    {
        if (false === ctype_digit($id)) {
            return null;
        }

        $labels = $this->labelRepository->findByAccountAndIds($account->getId(), [(int) $id]);

        return $labels[0] ?? null;
    }
}
