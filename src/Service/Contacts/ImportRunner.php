<?php

declare(strict_types=1);

namespace App\Service\Contacts;

use App\Entity\User\User;
use App\Repository\User\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persists ParsedContact DTOs as contacts (source 'import:vcf' or
 * 'import:csv'), then runs DuplicateFinder over the new batch.
 *
 * IN CHUNKS, WITH THE ENTITY MANAGER CLEARED BETWEEN THEM (#672). Every
 * contact is three flushes — the contact, its addresses, its numbers — and a
 * flush looks at every entity the manager holds. Holding all of them made the
 * import quadratic: a thousand contacts took forty-three seconds, inside the
 * request, which is how it came to be a background job. Clearing every CHUNK
 * contacts keeps each flush small; the owner is read again afterwards, since
 * the clear detaches it.
 */
final class ImportRunner
{
    private const int CHUNK = 100;

    public function __construct(
        private readonly ContactManager $manager,
        private readonly DuplicateFinder $finder,
        private readonly ContactPhotoStore $photos,
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @param list<ParsedContact>  $dtos
     * @param (callable(int): void)|null $progress told how many are in after each chunk
     *
     * @return array{imported: list<int>, duplicates: list<DuplicatePair>}
     */
    public function run(User $user, array $dtos, string $source, ?callable $progress = null): array
    {
        if (!in_array($source, ['import:vcf', 'import:csv'], true)) {
            throw new \InvalidArgumentException('Unsupported import source: ' . $source);
        }

        $userId   = (int) $user->id;
        $imported = [];
        foreach ($dtos as $dto) {
            $data = [
                'displayName' => $this->resolveDisplayName($dto),
                'tags' => $dto->tags,
                'source' => $source,
                // A birthday the source wrote some other way is dropped, not
                // a reason to refuse the contact.
                'birthday' => ContactFields::date($dto->birthday),
            ];

            foreach ([...ContactFields::SCALARS, ...ContactFields::LISTS] as $field) {
                $data[$field] = $dto->{$field};
            }

            $contact = $this->manager->create($user, $data);

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

            $imported[] = (int) $contact->id;

            if (0 === count($imported) % self::CHUNK) {
                $this->em->clear();
                $user = $this->users->find($userId) ?? throw new \RuntimeException('The importing user is gone.');

                if (null !== $progress) {
                    $progress(count($imported));
                }
            }
        }

        if (null !== $progress) {
            $progress(count($imported));
        }

        $duplicates = $this->finder->findCandidates($user, $imported);

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
