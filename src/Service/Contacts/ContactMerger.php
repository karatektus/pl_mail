<?php

declare(strict_types=1);

namespace App\Service\Contacts;

use App\Domain\Helper\AddressHelper;
use App\Entity\Contacts\Contact;
use App\Entity\Contacts\ContactAddress;
use App\Entity\Contacts\ContactEmail;
use App\Entity\Contacts\ContactMergeRecord;
use App\Entity\Contacts\ContactPhone;
use App\Entity\Contacts\ContactRelation;
use App\Entity\User\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Merges duplicate contacts into a kept contact and can undo the merge
 * within the 90-day window.
 *
 * Child rows (emails/phones/addresses) move to the kept contact, deduplicated
 * on their normalized values. Empty scalar fields on the kept contact are
 * filled from the merged ones; tags are unioned; notes are appended.
 * Relations involving merged contacts are repointed to the kept contact
 * (pre-checked for unique-key duplicates, which are dropped instead).
 * Merged contacts' photos are left on disk (orphaned) — only the kept
 * contact's photo is retained.
 */
final class ContactMerger
{
    public function __construct(
        private EntityManagerInterface $em,
        private ContactPhotoStore $photos,
    ) {
    }

    public function merge(int $keepId, array $mergeIds, User $user): ContactMergeRecord
    {
        $keep = $this->em->find(Contact::class, $keepId);
        if (null === $keep) {
            throw new \InvalidArgumentException('Contact to keep not found: ' . $keepId);
        }
        $this->assertOwner($keep, $user);

        $mergeIds = array_values(array_unique(array_filter(
            array_map('intval', $mergeIds),
            static fn (int $id) => $id !== $keepId,
        )));
        $merged = [];
        foreach ($mergeIds as $id) {
            $contact = $this->em->find(Contact::class, $id);
            if (null === $contact) {
                throw new \InvalidArgumentException('Contact to merge not found: ' . $id);
            }
            $this->assertOwner($contact, $user);
            $merged[] = $contact;
        }

        $snapshot = [
            'kept' => $this->snapshotContact($keep),
            'merged' => array_map(fn (Contact $c) => $this->snapshotContact($c), $merged),
        ];

        foreach ($merged as $contact) {
            $this->moveChildren($keep, $contact);
            $this->fillScalars($keep, $contact);
            $this->mergeTags($keep, $contact);
            $this->mergeNotes($keep, $contact);
            $this->repointRelations($keep, $contact);
            $this->em->remove($contact);
        }

        $record = ContactMergeRecord::create($user, $keep, $mergeIds, $snapshot);
        $this->em->persist($record);
        $this->em->flush();

        return $record;
    }

    public function undo(int $recordId, User $user): void
    {
        $record = $this->em->find(ContactMergeRecord::class, $recordId);
        if (null === $record) {
            throw new \InvalidArgumentException('Merge record not found: ' . $recordId);
        }
        $this->assertOwner($record, $user);

        if (new DateTimeImmutable() > $record->undoUntil) {
            throw new \RuntimeException('merge undo window expired');
        }

        $keep = $record->keptContact;
        if (null === $keep) {
            throw new \InvalidArgumentException('Kept contact no longer exists; cannot undo merge');
        }
        $this->assertOwner($keep, $user);

        $keptSnapshot = $record->snapshot['kept'] ?? [];
        foreach (['displayName', 'givenName', 'familyName', 'nickname', 'company', 'jobTitle', 'notes'] as $key) {
            $keep->{$key} = $keptSnapshot[$key] ?? null;
        }
        $keep->tags = $keptSnapshot['tags'] ?? [];

        $currentPhoto = $keep->photoPath;
        $snapshotPhoto = $keptSnapshot['photoPath'] ?? null;
        if ($currentPhoto !== $snapshotPhoto) {
            if (null !== $currentPhoto && '' !== $currentPhoto) {
                $this->photos->delete($currentPhoto);
            }
            $keep->photoPath = $snapshotPhoto;
        }

        // Drop current child rows, recreate from the snapshot.
        foreach (['emails', 'phones', 'addresses'] as $collection) {
            foreach ($keep->{$collection} as $child) {
                $this->em->remove($child);
            }
        }
        $this->recreateChildren($keep, $keptSnapshot);

        // Recreate merged contacts. NOTE: they get new ids.
        foreach ($record->snapshot['merged'] ?? [] as $mergedSnapshot) {
            $recreated = Contact::create($user, (string) ($mergedSnapshot['displayName'] ?? 'Unnamed contact'));
            foreach (['givenName', 'familyName', 'nickname', 'company', 'jobTitle', 'notes', 'photoPath', 'source'] as $key) {
                $recreated->{$key} = $mergedSnapshot[$key] ?? null;
            }
            $recreated->tags = $mergedSnapshot['tags'] ?? [];
            $this->em->persist($recreated);
            $this->recreateChildren($recreated, $mergedSnapshot);
        }

        $this->em->remove($record);
        $this->em->flush();
    }

    private function assertOwner(object $entity, User $user): void
    {
        $ownerId = $entity->usr?->id;
        if ($ownerId !== $user->id) {
            throw new \InvalidArgumentException('Entity does not belong to this user');
        }
    }

    private function moveChildren(Contact $keep, Contact $merged): void
    {
        $keptEmails = [];
        foreach ($keep->emails as $email) {
            $keptEmails[] = AddressHelper::email($email->email);
        }
        foreach ($merged->emails as $email) {
            $normalized = AddressHelper::email($email->email);
            if (in_array($normalized, $keptEmails, true)) {
                $this->em->remove($email);
            } else {
                $email->moveTo($keep);
                $keptEmails[] = $normalized;
            }
        }

        $keptPhones = [];
        foreach ($keep->phones as $phone) {
            $keptPhones[] = preg_replace('/\D+/', '', (string) $phone->phone) ?? '';
        }
        foreach ($merged->phones as $phone) {
            $key = preg_replace('/\D+/', '', (string) $phone->phone) ?? '';
            if (in_array($key, $keptPhones, true)) {
                $this->em->remove($phone);
            } else {
                $phone->moveTo($keep);
                $keptPhones[] = $key;
            }
        }

        $keptAddresses = [];
        foreach ($keep->addresses as $address) {
            $keptAddresses[] = $this->addressKey($address);
        }
        foreach ($merged->addresses as $address) {
            if (in_array($this->addressKey($address), $keptAddresses, true)) {
                $this->em->remove($address);
            } else {
                $address->moveTo($keep);
                $keptAddresses[] = $this->addressKey($address);
            }
        }
    }

    private function addressKey(ContactAddress $address): string
    {
        return implode('|', [
            mb_strtolower(trim((string) $address->label)),
            mb_strtolower(trim((string) $address->street)),
            mb_strtolower(trim((string) $address->city)),
        ]);
    }

    private function fillScalars(Contact $keep, Contact $merged): void
    {
        foreach (['givenName', 'familyName', 'nickname', 'company', 'jobTitle'] as $key) {
            if ((null === $keep->{$key} || '' === $keep->{$key})
                && null !== $merged->{$key} && '' !== $merged->{$key}
            ) {
                $keep->{$key} = $merged->{$key};
            }
        }
    }

    private function mergeTags(Contact $keep, Contact $merged): void
    {
        $keep->tags = array_values(array_unique(array_merge($keep->tags, $merged->tags)));
    }

    private function mergeNotes(Contact $keep, Contact $merged): void
    {
        $notes = trim((string) $merged->notes);
        if ('' === $notes) {
            return;
        }
        $current = trim((string) $keep->notes);
        if ('' === $current) {
            $keep->notes = $merged->notes;
        } elseif (!str_contains($current, $notes)) {
            $keep->notes = $current . "\n\n---\n\n" . $notes;
        }
    }

    /**
     * Repoints relations touching the merged contact onto the kept contact.
     * A relation that would violate the (owner, related, relType) unique key
     * (because the kept contact already has it) is dropped instead. Self
     * relations created by the repoint are dropped too.
     */
    private function repointRelations(Contact $keep, Contact $merged): void
    {
        $repository = $this->em->getRepository(ContactRelation::class);

        $relations = array_merge(
            $repository->findBy(['ownerContact' => $merged]),
            $repository->findBy(['relatedContact' => $merged]),
        );

        foreach ($relations as $relation) {
            $newOwner = $relation->ownerContact === $merged ? $keep : $relation->ownerContact;
            $newRelated = $relation->relatedContact === $merged ? $keep : $relation->relatedContact;

            if ($newOwner === $newRelated) {
                $this->em->remove($relation);
                continue;
            }

            $duplicate = $repository->findOneBy([
                'ownerContact' => $newOwner,
                'relatedContact' => $newRelated,
                'relType' => $relation->relType,
            ]);
            if (null !== $duplicate && $duplicate !== $relation) {
                $this->em->remove($relation);
                continue;
            }

            $relation->repoint($newOwner, $newRelated);
        }
    }

    /**
     * Full pre-merge copy of a contact: scalars, tags, photoPath, source and
     * all child rows. Relations are NOT snapshotted (they are repointed, not
     * preserved, by merge; undo does not restore them).
     */
    private function snapshotContact(Contact $contact): array
    {
        $emails = [];
        foreach ($contact->emails as $email) {
            $emails[] = [
                'email' => $email->email,
                'label' => $email->label,
                'isPrimary' => $email->isPrimary,
            ];
        }
        $phones = [];
        foreach ($contact->phones as $phone) {
            $phones[] = [
                'phone' => $phone->phone,
                'label' => $phone->label,
                'isPrimary' => $phone->isPrimary,
            ];
        }
        $addresses = [];
        foreach ($contact->addresses as $address) {
            $addresses[] = [
                'label' => $address->label,
                'street' => $address->street,
                'city' => $address->city,
                'region' => $address->region,
                'postcode' => $address->postcode,
                'country' => $address->country,
            ];
        }

        return [
            'id' => $contact->id,
            'displayName' => $contact->displayName,
            'givenName' => $contact->givenName,
            'familyName' => $contact->familyName,
            'nickname' => $contact->nickname,
            'company' => $contact->company,
            'jobTitle' => $contact->jobTitle,
            'notes' => $contact->notes,
            'tags' => $contact->tags,
            'photoPath' => $contact->photoPath,
            'source' => $contact->source,
            'emails' => $emails,
            'phones' => $phones,
            'addresses' => $addresses,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function recreateChildren(Contact $contact, array $snapshot): void
    {
        foreach ($snapshot['emails'] ?? [] as $row) {
            $email = ContactEmail::create(
                $contact,
                (string) ($row['email'] ?? ''),
                (string) ($row['label'] ?? 'other'),
                (bool) ($row['isPrimary'] ?? false),
            );
            $this->em->persist($email);
        }
        foreach ($snapshot['phones'] ?? [] as $row) {
            $phone = ContactPhone::create(
                $contact,
                (string) ($row['phone'] ?? ''),
                (string) ($row['label'] ?? 'other'),
                (bool) ($row['isPrimary'] ?? false),
            );
            $this->em->persist($phone);
        }
        foreach ($snapshot['addresses'] ?? [] as $row) {
            $address = ContactAddress::create($contact, (string) ($row['label'] ?? 'other'));
            foreach (['street', 'city', 'region', 'postcode', 'country'] as $key) {
                $address->{$key} = $row[$key] ?? null;
            }
            $this->em->persist($address);
        }
    }
}
