<?php

declare(strict_types=1);

namespace App\Jmap\Method\Mail;

use App\Domain\Enum\Mail\LabelColor;
use App\Entity\Mail\Account;
use App\Entity\Label\Label;
use App\Entity\Label\LabelBinding;
use App\Jmap\Account\AccountResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Label\LabelBindingRepository;
use App\Repository\Label\LabelRepository;
use App\Service\Label\LabelResolver;
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
        private readonly LabelBindingRepository $bindingRepository,
        private readonly LabelResolver $labelResolver,
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
                // Resolved before the Label is constructed, so an unusable
                // colour fails the create outright. Accepting the create and
                // dropping the colour is what this method used to do, and it is
                // the worst of the three options: the client is told the label
                // is exactly what it asked for.
                $color = $this->requireColor($properties['color'] ?? null);
            } catch (MethodException $exception) {
                $notCreated[$creationId] = $exception->toError();
                continue;
            }

            $label            = new Label();
            $label->usr       = $account->getUsr();
            $label->parent    = $parent;
            $label->name      = $name;
            $label->color     = $color?->value;
            $label->isVisible = true !== ($properties['isSubscribed'] ?? true) ? false : true;

            if (true === is_int($properties['sortOrder'] ?? null)) {
                $label->sortOrder = $properties['sortOrder'];
            }

            $this->entityManager->persist($label);
            // The id is needed for the response and the propagator, and the
            // Mailbox state token must move before this method returns.
            $this->entityManager->flush();

            // A JMAP Mailbox is a binding, so creating one materializes the
            // label on this account; the binding's id is the Mailbox id.
            // LabelResolver::binding() records the state change itself.
            $binding = $this->labelResolver->binding($label, $account);

            $this->propagator->created($label);
            $context->recordCreatedId($creationId, (string) $binding->id);

            $created[$creationId] = [
                'id' => (string) $binding->id,
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

            $binding = $this->findBinding($account, $context->resolveId($id) ?? $id);

            if (null === $binding) {
                $notUpdated[$id] = ['type' => 'notFound', 'description' => 'No such Mailbox in this account.'];
                continue;
            }

            $label = $binding->label;

            try {
                $renamed = $this->applyPatch($account, $label, $patch, $context);
            } catch (MethodException $exception) {
                $notUpdated[$id] = $exception->toError();
                continue;
            }

            if (true === $renamed) {
                $this->propagator->renamed($label);
            }

            $this->stateManager->recordUpdated($account->getId(), JmapObjectType::Mailbox, (string) $binding->id);
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
                    $label->name = $name;
                    $structural = true;
                    break;

                case 'parentId':
                    $this->assertMutable($label);
                    $parent = $this->resolveParent($account, $value, $context);
                    $this->assertNoCycle($label, $parent);
                    $this->assertNameFree($account, $parent, (string) $label->name, $label);
                    $label->parent = $parent;
                    $structural = true;
                    break;

                case 'isSubscribed':
                    if (false === is_bool($value)) {
                        throw new MethodException('invalidProperties', '"isSubscribed" must be a boolean.');
                    }

                    $label->isVisible = $value;
                    break;

                case 'sortOrder':
                    if (false === is_int($value)) {
                        throw new MethodException('invalidProperties', '"sortOrder" must be an integer.');
                    }

                    $label->sortOrder = $value;
                    break;

                case 'color':
                    // Not guarded by assertMutable: a system label's colour is
                    // the one thing about it the user may choose. Renaming or
                    // deleting Inbox would break the invariants that depend on
                    // the role; recolouring its chip breaks nothing.
                    //
                    // Null clears the colour, which is why this reads the value
                    // rather than testing it for emptiness — "no colour" is a
                    // choice a user can make and has to be expressible.
                    $label->color = $this->requireColor($value)?->value;
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
            $binding = $this->findBinding($account, $context->resolveId($id) ?? $id);

            if (null === $binding) {
                $notDestroyed[$id] = ['type' => 'notFound', 'description' => 'No such Mailbox in this account.'];
                continue;
            }

            $label = $binding->label;

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
            // name off the bindings, and there is nothing to read afterwards.
            $this->propagator->deleted($label);
            $this->stateManager->recordDestroyed($account->getId(), JmapObjectType::Mailbox, (string) $binding->id);

            // A Mailbox is per-account, so destroying one un-materializes the
            // label HERE — it must not vanish from the user's other accounts.
            // The label row only goes when its last binding does; that is also
            // what drops the message_label rows, so mail stays labelled as long
            // as any account still uses the label.
            $this->detachAccountMessages($account, $label);
            $this->entityManager->remove($binding);
            $label->removeBinding($binding);

            if (count($label->bindings) === 0) {
                $this->entityManager->remove($label);
            }

            $destroyed[] = $id;
        }
    }

    /**
     * Detach a label from every message of one account, leaving the other
     * accounts' messages labelled. Raw SQL because this is a bulk delete on a
     * join table with no entity of its own.
     */
    private function detachAccountMessages(Account $account, Label $label): void
    {
        $connection = $this->entityManager->getConnection();

        $connection->executeStatement(
            'DELETE FROM message_label ml
             USING message m
             WHERE ml.message_id = m.id
               AND ml.label_id = :labelId
               AND m.account_id = :accountId',
            ['labelId' => $label->id, 'accountId' => $account->getId()],
        );

        $connection->executeStatement(
            'DELETE FROM thread_label tl
             USING message_thread t
             WHERE tl.message_thread_id = t.id
               AND tl.label_id = :labelId
               AND t.account_id = :accountId',
            ['labelId' => $label->id, 'accountId' => $account->getId()],
        );
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

    /**
     * A colour token, or null for "no colour".
     *
     * Rejects rather than ignores. The vocabulary is closed on purpose — a
     * Tailwind token resolves per theme where a hex value does not — so a
     * client sending "#ff0000" is asking for something this server cannot
     * store, and the useful answer says so. Silently keeping null would leave
     * that client redrawing an uncoloured chip forever with nothing to debug,
     * which is precisely the bug this method is being fixed for.
     *
     * The error names the accepted values, because a closed vocabulary the
     * caller cannot discover is only marginally better than no vocabulary.
     */
    private function requireColor(mixed $color): ?LabelColor
    {
        if (null === $color) {
            return null;
        }

        if (false === is_string($color)) {
            throw new MethodException('invalidProperties', '"color" must be a string or null.');
        }

        $resolved = LabelColor::tryFrom($color);

        if (null === $resolved) {
            throw new MethodException('invalidProperties', sprintf(
                '"%s" is not a known color. Use one of: %s, or null for none.',
                $color,
                implode(', ', array_column(LabelColor::cases(), 'value')),
            ));
        }

        return $resolved;
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
        $existing = $this->labelRepository->findOneChildByName($account->getUsr(), $parent, $name);

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

    /**
     * JMAP Mailbox ids are LabelBinding ids, so an id only resolves when the
     * label is actually materialized on the requesting account.
     */
    private function findBinding(Account $account, string $id): ?LabelBinding
    {
        if (false === ctype_digit($id)) {
            return null;
        }

        $bindings = $this->bindingRepository->findForAccountAndIds((int) $account->getId(), [(int) $id]);

        return $bindings[0] ?? null;
    }

    private function findLabel(Account $account, string $id): ?Label
    {
        return $this->findBinding($account, $id)?->label;
    }
}
