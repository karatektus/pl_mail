<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Domain\Helper\AddressHelper;
use App\Entity\Mail\Message;
use App\Repository\Mail\ContactRepository;
use App\Repository\Mail\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rewrites stored display names and addresses into canonical form.
 *
 * Rows synced before AddressHelper existed kept whatever the backend handed
 * over: a quoted-string display name arrived as `"Doe, John"` from php-imap and
 * from Graph, and the Gmail path split an address list on every comma, so one
 * quoted name could become two "addresses" — the first of them a bare `"Doe`.
 * Those spellings are what the quotes in the message list and in contact names
 * come from.
 *
 * Idempotent: normalising an already-normalised value is the identity.
 *
 * Contact *emails* are reported, not rewritten. Rewriting one can collide with
 * the (usr_id, email) unique constraint, and merging two contacts means picking
 * a winner for frequency, display name and the correspondent flag — a decision
 * this task should not make silently. The counts tell you whether there is
 * anything to merge.
 */
final readonly class AddressNormalizationBackfillTask implements BackfillTaskInterface
{
    private const int BATCH_SIZE = 500;

    public function __construct(
        private MessageRepository      $messageRepository,
        private ContactRepository      $contactRepository,
        private EntityManagerInterface $em,
    ) {}

    public function getName(): string
    {
        return 'addresses';
    }

    public function getDescription(): string
    {
        return 'Strip RFC 5322 quoting from stored sender/recipient names and canonicalise addresses';
    }

    public function run(SymfonyStyle $io): int
    {
        $this->normalizeMessages($io);
        $this->normalizeContacts($io);

        return 0;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function normalizeMessages(SymfonyStyle $io): void
    {
        $afterId = 0;
        $seen    = 0;
        $changed = 0;

        while (true) {
            $ids = $this->messageRepository->findIdsAfter($afterId, self::BATCH_SIZE);

            if (0 === count($ids)) {
                break;
            }

            foreach ($this->messageRepository->findByIds($ids) as $message) {
                if (true === $this->normalizeMessage($message)) {
                    $changed++;
                }
            }

            $seen   += count($ids);
            $afterId = $ids[count($ids) - 1];

            $this->em->flush();
            $this->em->clear();

            $io->writeln(sprintf('  … %d messages scanned, %d rewritten', $seen, $changed));
        }

        $io->success(sprintf('%d messages scanned, %d rewritten.', $seen, $changed));
    }

    private function normalizeMessage(Message $message): bool
    {
        $changed = false;

        // A null stays null: it means "no such header", which is not the same
        // claim as an empty string, and rewriting it would dirty every row.
        if (null !== $message->getFromName()) {
            $fromName = AddressHelper::name($message->getFromName());

            if ($fromName !== $message->getFromName()) {
                $message->setFromName($fromName);
                $changed = true;
            }
        }

        if (null !== $message->getFromAddress()) {
            $fromAddress = AddressHelper::email($message->getFromAddress());

            if ($fromAddress !== $message->getFromAddress()) {
                $message->setFromAddress($fromAddress);
                $changed = true;
            }
        }

        $groups = [
            [$message->getToAddresses(), $message->setToAddresses(...)],
            [$message->getCcAddresses(), $message->setCcAddresses(...)],
            [$message->getBccAddresses(), $message->setBccAddresses(...)],
        ];

        foreach ($groups as [$group, $setter]) {
            if (null === $group || 0 === count($group)) {
                continue;
            }

            $normalized = $this->normalizeGroup($group);

            if ($normalized !== $group) {
                $setter($normalized);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * @param array<array{name?: string|null, address?: string|null}> $group
     *
     * @return list<array{name: string, address: string}>
     */
    private function normalizeGroup(array $group): array
    {
        $normalized = [];

        foreach ($group as $entry) {
            $address = AddressHelper::email($entry['address'] ?? null);

            // A fragment left behind by the old comma split ("Doe) is not an
            // address and never was — dropping it is the whole point.
            if (false === AddressHelper::isValidEmail($address)) {
                continue;
            }

            $normalized[] = [
                'name'    => AddressHelper::name($entry['name'] ?? null),
                'address' => $address,
            ];
        }

        return $normalized;
    }

    private function normalizeContacts(SymfonyStyle $io): void
    {
        $afterId  = 0;
        $seen     = 0;
        $changed  = 0;
        $suspect  = [];

        while (true) {
            $ids = $this->contactRepository->findIdsAfter($afterId, self::BATCH_SIZE);

            if (0 === count($ids)) {
                break;
            }

            foreach ($this->contactRepository->findByIds($ids) as $contact) {
                $name = AddressHelper::name($contact->getDisplayName());

                if ($name !== ($contact->getDisplayName() ?? '')) {
                    $contact->setDisplayName('' !== $name ? $name : null);
                    $changed++;
                }

                $email = (string) $contact->getEmail();

                if (false === AddressHelper::isValidEmail($email) || $email !== AddressHelper::email($email)) {
                    $suspect[] = $email;
                }
            }

            $seen   += count($ids);
            $afterId = $ids[count($ids) - 1];

            $this->em->flush();
            $this->em->clear();

            $io->writeln(sprintf('  … %d contacts scanned, %d names rewritten', $seen, $changed));
        }

        $io->success(sprintf('%d contacts scanned, %d display names rewritten.', $seen, $changed));

        if (0 === count($suspect)) {
            return;
        }

        $io->warning(sprintf(
            '%d contact(s) have an email that is not a canonical valid address. They are left alone: '
            . 'rewriting one can collide with the (usr_id, email) unique constraint, so removing or '
            . 'merging them is a call to make by hand.',
            count($suspect),
        ));
        $io->listing(array_slice($suspect, 0, 20));
    }
}
