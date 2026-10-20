<?php

declare(strict_types=1);

namespace App\Service\Contacts;

use App\Domain\Helper\AddressHelper;
use App\Entity\Contacts\Contact;
use App\Entity\Contacts\ContactAddress;
use App\Entity\Contacts\ContactEmail;
use App\Entity\Contacts\ContactPhone;
use App\Entity\User\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CRUD + child-row management for address-book contacts.
 */
final class ContactManager
{
    public function __construct(
        private EntityManagerInterface $em,
        private ContactPhotoStore $photos,
    ) {
    }

    /**
     * @param array{displayName?: string, givenName?: ?string, familyName?: ?string, nickname?: ?string, company?: ?string, jobTitle?: ?string, notes?: ?string, tags?: string[], source?: string} $data
     */
    public function create(User $owner, array $data): Contact
    {
        $displayName = trim((string) ($data['displayName'] ?? ''));
        if ('' === $displayName) {
            throw new \InvalidArgumentException('displayName is required and cannot be empty');
        }

        $contact = Contact::create($owner, $displayName);
        $contact->source = (string) ($data['source'] ?? 'manual');
        $this->applyScalars($contact, $data);
        if (array_key_exists('tags', $data)) {
            $contact->tags = $this->cleanTags($data['tags']);
        }

        $this->em->persist($contact);
        $this->em->flush();

        return $contact;
    }

    /**
     * @param array{displayName?: string, givenName?: ?string, familyName?: ?string, nickname?: ?string, company?: ?string, jobTitle?: ?string, notes?: ?string, tags?: string[]} $data
     */
    public function update(Contact $contact, array $data): Contact
    {
        if (array_key_exists('displayName', $data)) {
            $displayName = trim((string) $data['displayName']);
            if ('' === $displayName) {
                throw new \InvalidArgumentException('displayName cannot be empty');
            }
            $contact->displayName = $displayName;
        }
        $this->applyScalars($contact, $data);
        if (array_key_exists('tags', $data)) {
            $contact->tags = $this->cleanTags($data['tags']);
        }

        $this->em->flush();

        return $contact;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyScalars(Contact $contact, array $data): void
    {
        foreach (['givenName', 'familyName', 'nickname', 'company', 'jobTitle', 'notes'] as $key) {
            if (array_key_exists($key, $data)) {
                $value = $data[$key];
                $contact->{$key} = (null === $value || '' === trim((string) $value))
                    ? null
                    : trim((string) $value);
            }
        }
    }

    /**
     * @param mixed $tags
     * @return list<string>
     */
    private function cleanTags($tags): array
    {
        if (!is_array($tags)) {
            throw new \InvalidArgumentException('tags must be an array of strings');
        }
        $out = [];
        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            if ('' !== $tag && !in_array($tag, $out, true)) {
                $out[] = $tag;
            }
        }

        return $out;
    }

    public function softDelete(Contact $contact): void
    {
        $contact->trashedAt = new DateTimeImmutable();
        $this->em->flush();
    }

    public function restore(Contact $contact): void
    {
        $contact->trashedAt = null;
        $this->em->flush();
    }

    /**
     * Hard-deletes the user's contacts trashed more than 90 days ago,
     * removing their photo files as well. Returns the number purged.
     */
    public function permanentPurge(User $user): int
    {
        $cutoff = new DateTimeImmutable('-90 days');
        $stale = $this->em->createQueryBuilder()
            ->select('c')
            ->from(Contact::class, 'c')
            ->where('c.usr = :user')
            ->andWhere('c.trashedAt < :cutoff')
            ->setParameter('user', $user)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($stale as $contact) {
            if (null !== $contact->photoPath && '' !== $contact->photoPath) {
                $this->photos->delete($contact->photoPath);
            }
            $this->em->remove($contact);
            ++$count;
        }
        $this->em->flush();

        return $count;
    }

    public function addEmail(Contact $contact, string $email, string $label = 'other', bool $primary = false): ContactEmail
    {
        $normalized = AddressHelper::email($email);
        if (!AddressHelper::isValidEmail($normalized)) {
            throw new \InvalidArgumentException('Invalid email address: ' . $email);
        }

        foreach ($contact->emails as $existing) {
            if ($existing->email === $normalized) {
                return $existing;
            }
        }

        if ($primary) {
            foreach ($contact->emails as $existing) {
                $existing->isPrimary = false;
            }
        }

        $row = ContactEmail::create($contact, $normalized, $label, $primary);
        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }

    public function addPhone(Contact $contact, string $phone, string $label = 'other', bool $primary = false): ContactPhone
    {
        $normalized = $this->normalizePhone($phone);

        foreach ($contact->phones as $existing) {
            if ($existing->phone === $normalized) {
                return $existing;
            }
        }

        if ($primary) {
            foreach ($contact->phones as $existing) {
                $existing->isPrimary = false;
            }
        }

        $row = ContactPhone::create($contact, $normalized, $label, $primary);
        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }

    /**
     * Strips spaces/dashes/parens/dots, keeps digits and a single leading +.
     */
    private function normalizePhone(string $phone): string
    {
        $phone = trim($phone);
        $plus = str_starts_with($phone, '+');
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ('' === $digits) {
            throw new \InvalidArgumentException('Phone number has no digits: ' . $phone);
        }

        return ($plus ? '+' : '') . $digits;
    }

    /**
     * @param array{label?: string, street?: ?string, city?: ?string, region?: ?string, postcode?: ?string, country?: ?string} $data
     */
    public function addAddress(Contact $contact, array $data): ContactAddress
    {
        $row = ContactAddress::create($contact, (string) ($data['label'] ?? 'other'));
        foreach (['street', 'city', 'region', 'postcode', 'country'] as $key) {
            $value = $data[$key] ?? null;
            $row->{$key} = (null === $value || '' === trim((string) $value))
                ? null
                : trim((string) $value);
        }
        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }
}
