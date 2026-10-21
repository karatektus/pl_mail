<?php

declare(strict_types=1);

namespace App\Service\Contacts;

use App\Entity\Contacts\Contact;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persists ParsedContact DTOs as contacts (source 'import:vcf' or
 * 'import:csv'), then runs DuplicateFinder over the new batch.
 */
final class ImportRunner
{
    public function __construct(
        private ContactManager $manager,
        private DuplicateFinder $finder,
        private ContactPhotoStore $photos,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<ParsedContact> $dtos
     * @return array{imported: list<Contact>, duplicates: list<DuplicatePair>}
     */
    public function run(User $user, array $dtos, string $source): array
    {
        if (!in_array($source, ['import:vcf', 'import:csv'], true)) {
            throw new \InvalidArgumentException('Unsupported import source: ' . $source);
        }

        $imported = [];
        foreach ($dtos as $dto) {
            $contact = $this->manager->create($user, [
                'displayName' => $this->resolveDisplayName($dto),
                'givenName' => $dto->givenName,
                'familyName' => $dto->familyName,
                'nickname' => $dto->nickname,
                'company' => $dto->company,
                'jobTitle' => $dto->jobTitle,
                'notes' => $dto->notes,
                'tags' => $dto->tags,
                'source' => $source,
            ]);

            foreach ($dto->emails as $email) {
                try {
                    $this->manager->addEmail($contact, $email['email'], $email['label'] ?? 'other');
                } catch (\InvalidArgumentException $e) {
                    // Skip invalid email rows, keep importing the rest.
                    continue;
                }
            }

            foreach ($dto->phones as $phone) {
                try {
                    $this->manager->addPhone($contact, $phone['phone'], $phone['label'] ?? 'other');
                } catch (\InvalidArgumentException $e) {
                    continue;
                }
            }

            foreach ($dto->addresses as $address) {
                $this->manager->addAddress($contact, $address);
            }

            if (null !== $dto->photoBytes && null !== $dto->photoMime) {
                try {
                    $contact->photoPath = $this->photos->storeBytes(
                        $dto->photoBytes,
                        $dto->photoMime,
                        (int) $user->id,
                    );
                    $this->em->flush();
                } catch (\InvalidArgumentException $e) {
                    // Unsupported/corrupt photo: keep the contact without one.
                }
            }

            $imported[] = $contact;
        }

        $ids = array_map(static fn (Contact $c) => $c->id, $imported);
        $duplicates = $this->finder->findCandidates($user, $ids);

        return ['imported' => $imported, 'duplicates' => $duplicates];
    }

    private function resolveDisplayName(ParsedContact $dto): string
    {
        $name = trim((string) $dto->displayName);
        if ('' !== $name) {
            return $name;
        }
        $fallback = trim(trim((string) $dto->givenName) . ' ' . trim((string) $dto->familyName));
        if ('' !== $fallback) {
            return $fallback;
        }
        $firstEmail = $dto->emails[0]['email'] ?? null;
        if (null !== $firstEmail && '' !== trim($firstEmail)) {
            return trim($firstEmail);
        }

        return 'Unnamed contact';
    }
}
