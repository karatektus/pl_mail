<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Mail\Contact;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use Doctrine\Common\Collections\Collection;
use App\Repository\Mail\ContactRepository;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Moves recipients between the two shapes the composer keeps them in.
 *
 * A Message stores addresses as {name, address} JSON, because that is what a
 * mail header is. The To/Cc/Bcc fields are Contact autocompletes, because that
 * is what an address book is. Neither shape can be dropped: the JSON survives a
 * contact being deleted, and the autocomplete cannot render an address that is
 * not a Contact row.
 *
 * So the two directions are here together, as one pair — they have to stay
 * inverse, and the interesting half is not the copying but the reconciliation
 * with the address book that hydrate() has to do on the way.
 */
final readonly class DraftAddressFields
{
    /** @var list<string> */
    private const array FIELDS = ['toAddresses', 'ccAddresses', 'bccAddresses'];

    public function __construct(
        private ContactRepository $contactRepository,
    ) {
    }

    /**
     * Fill the autocomplete fields from the addresses stored on the draft.
     *
     * Addresses typed freehand may have no contact row yet, so those are
     * harvested on the spot — the field cannot represent an address that is not
     * a Contact.
     */
    public function hydrate(FormInterface $form, Message $message, ?UserInterface $user): void
    {
        // The compose routes are behind the firewall, so this is a guard rather
        // than a case: without a user there is no address book to reconcile
        // against, and the fields stay as the form built them.
        if (false === $user instanceof User) {
            return;
        }

        $groups  = $this->storedAddresses($message);
        $pending = [];

        foreach ($groups as $addresses) {
            foreach ($addresses as $addr) {
                $email = mb_strtolower(trim($addr['address'] ?? ''));

                if ($email === '') {
                    continue;
                }

                $pending[$email] = ['email' => $email, 'name' => $addr['name'] ?? null];
            }
        }

        if (count($pending) === 0) {
            return;
        }

        $contacts = $this->contactRepository->findByEmailsForUser($user, array_keys($pending));

        $missing = array_values(array_filter(
            $pending,
            static fn(array $addr): bool => false === array_key_exists($addr['email'], $contacts),
        ));

        // Only upsert what is genuinely absent — upsertBatch bumps frequency,
        // and merely opening a draft is not a new contact signal.
        if (count($missing) > 0) {
            $this->contactRepository->upsertBatch($user, $missing);
            $contacts = $this->contactRepository->findByEmailsForUser($user, array_keys($pending));
        }

        foreach ($groups as $field => $addresses) {
            $selected = [];

            foreach ($addresses as $addr) {
                $email = mb_strtolower(trim($addr['address'] ?? ''));

                if (true === array_key_exists($email, $contacts)) {
                    $selected[] = $contacts[$email];
                }
            }

            $form->get($field)->setData($selected);
        }
    }

    /**
     * Write the selected contacts back onto the draft, replacing whatever the
     * Symfony CollectionType may have bound.
     *
     * An empty selection is deliberately not written through: the Tom Select
     * fields and the mapped collection are two sources for one property, and a
     * field that submitted nothing means "unchanged", not "cleared".
     */
    public function apply(FormInterface $form, Message $message): void
    {
        foreach (self::FIELDS as $field) {
            $addresses = $this->selectedAddresses($form, $field);

            if (false === empty($addresses)) {
                $message->{$field} = $addresses;
            }
        }
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function storedAddresses(Message $message): array
    {
        return [
            'toAddresses'  => $message->toAddresses ?? [],
            'ccAddresses'  => $message->ccAddresses ?? [],
            'bccAddresses' => $message->bccAddresses ?? [],
        ];
    }

    /**
     * @return list<array{name: string, address: string}>
     */
    private function selectedAddresses(FormInterface $form, string $field): array
    {
        /** @var Collection<int, Contact>|null $contacts */
        $contacts = $form->get($field)->getData();

        if (empty($contacts)) {
            return [];
        }

        $result = [];

        foreach ($contacts as $contact) {
            $result[] = [
                'name'    => $contact->displayName ?? '',
                'address' => $contact->email ?? '',
            ];
        }

        return array_values(array_filter($result, static fn(array $a): bool => $a['address'] !== ''));
    }
}
