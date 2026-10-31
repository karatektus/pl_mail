<?php

declare(strict_types=1);

namespace App\Jmap\Method\ContactCard;

use App\Domain\Exception\ContactReadOnlyException;
use App\Jmap\Account\ContactAccountResolver;
use App\Jmap\Contact\ContactCards;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Repository\Contacts\ContactRepository;
use App\Service\Contacts\ContactManager;
use App\Service\Contacts\Change\ContactChangeReader;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "ContactCard/set" (RFC 9610 §3.3): create, update and destroy, through
 * ContactCards and VCardMapper — see there.
 *
 * A destroy moves the contact to the bin, as deleting one on the contact page
 * does, and it is gone to every JMAP client from then on. A card in a mirrored
 * book takes all three, and the change goes on to that book's server
 * (ContactSyncTracker); one in a read-only book answers forbidden.
 */
final class ContactCardSetMethod implements JmapMethod
{
    public function __construct(
        private readonly ContactAccountResolver $accounts,
        private readonly ContactRepository $contacts,
        private readonly ContactCards $cards,
        private readonly ContactManager $manager,
        private readonly ContactChangeReader $changes,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function name(): string
    {
        return 'ContactCard/set';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account  = $this->accounts->resolve($context->user, $arguments['accountId'] ?? null);
        $userId   = (int) $context->user->id;
        $oldState = $this->changes->cardState($userId);

        if (null !== ($arguments['ifInState'] ?? null) && $arguments['ifInState'] !== $oldState) {
            throw new MethodException('stateMismatch', 'The contacts have changed since that state.');
        }

        $created = $notCreated = $updated = $notUpdated = $destroyed = $notDestroyed = [];

        foreach ($this->objects($arguments, 'create') as $creationId => $card) {
            try {
                $contact = $this->cards->create($context->user, $this->card($card));
                $id      = (string) $contact->id;

                $context->recordCreatedId((string) $creationId, $id);
                $created[(string) $creationId] = ['id' => $id, 'uid' => $contact->uid];
            } catch (MethodException $e) {
                $notCreated[(string) $creationId] = $e->toError();
            } catch (ContactReadOnlyException $e) {
                $this->reopen();
                $notCreated[(string) $creationId] = ['type' => 'forbidden', 'description' => $e->getMessage()];
            }
        }

        foreach ($this->objects($arguments, 'update') as $id => $patch) {
            $id      = $context->resolveId((string) $id) ?? (string) $id;
            $contact = $this->contacts->findOneCardForUser($context->user, $id);

            if (null === $contact) {
                $notUpdated[$id] = ['type' => 'notFound'];

                continue;
            }

            try {
                $this->cards->update($context->user, $contact, $this->card($patch));
                $updated[$id] = null;
            } catch (MethodException $e) {
                $notUpdated[$id] = $e->toError();
            } catch (ContactReadOnlyException $e) {
                $this->reopen();
                $notUpdated[$id] = ['type' => 'forbidden', 'description' => $e->getMessage()];
            }
        }

        $destroy = $arguments['destroy'] ?? [];

        if (false === is_array($destroy)) {
            throw new MethodException('invalidArguments', '"destroy" must be an array of ids.');
        }

        foreach ($destroy as $id) {
            $id      = $context->resolveId((string) $id) ?? (string) $id;
            $contact = $this->contacts->findOneCardForUser($context->user, $id);

            if (null === $contact) {
                $notDestroyed[$id] = ['type' => 'notFound'];

                continue;
            }

            if (false === $contact->isEditable()) {
                $notDestroyed[$id] = ['type' => 'forbidden', 'description' => 'This card is in a read-only address book.'];

                continue;
            }

            $this->manager->softDelete($contact);
            $destroyed[] = $id;
        }

        return [
            'accountId'    => (string) $account->id,
            'oldState'     => $oldState,
            'newState'     => $this->changes->cardState($userId),
            'created'      => [] === $created ? null : $created,
            'notCreated'   => [] === $notCreated ? null : $notCreated,
            'updated'      => [] === $updated ? null : $updated,
            'notUpdated'   => [] === $notUpdated ? null : $notUpdated,
            'destroyed'    => [] === $destroyed ? null : $destroyed,
            'notDestroyed' => [] === $notDestroyed ? null : $notDestroyed,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<array-key, mixed>
     */
    private function objects(array $arguments, string $key): array
    {
        $objects = $arguments[$key] ?? [];

        if (false === is_array($objects)) {
            throw new MethodException('invalidArguments', sprintf('"%s" must be an object.', $key));
        }

        return $objects;
    }

    /** @return array<string, mixed> */
    private function card(mixed $card): array
    {
        if (false === is_array($card)) {
            throw new MethodException('invalidProperties', 'Each card must be an object.');
        }

        return $card;
    }

    /** A refused flush closes the manager; the rest of the request still runs. */
    private function reopen(): void
    {
        if (false === $this->em->isOpen()) {
            throw new MethodException('serverFail', 'A refused write closed the session; send the rest again.');
        }

        $this->em->clear();
    }
}
