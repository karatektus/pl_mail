<?php

declare(strict_types=1);

namespace App\Service\Mail\SenderPicture;

use App\Domain\Enum\Mail\SenderPictureKind;
use App\Domain\Enum\Mail\SenderPictureStatus;
use App\Domain\Exception\SenderPictureUnavailableException;
use App\Domain\Interface\SenderPictureLookupInterface;
use App\Entity\Mail\SenderPicture;
use App\Repository\Mail\SenderPicture\SenderPictureRepository;
use App\Repository\System\SenderPictureSettings\SenderPictureSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Does one claimed lookup (#639): asks the source, checks and stores what it
 * answered, and records the answer on the row.
 *
 * What the background job is, minus the queue. The handler calls it, and so
 * would anything else that ever needs a lookup done now.
 *
 * **Asks the administrator's switch again**, here at the far end of the
 * queue, because lookups claimed while it was on may still be waiting when
 * somebody turns it off. Those are left Pending rather than answered: the row
 * is reclaimed by the next sighting after it goes stale, and nothing was
 * asked of anybody in between.
 */
final readonly class SenderPictureResolver
{
    /** @var array<string, SenderPictureLookupInterface> */
    private array $lookups;

    /**
     * @param iterable<SenderPictureLookupInterface> $lookups
     */
    public function __construct(
        #[AutowireIterator('app.sender_picture_lookup')]
        iterable                                $lookups,
        private SenderPictureRepository         $pictures,
        private SenderPictureSettingsRepository $settings,
        private SenderPictureImage              $image,
        private SenderPictureStore              $store,
        private EntityManagerInterface          $em,
        private LoggerInterface                 $logger,
    ) {
        $byKind = [];

        foreach ($lookups as $lookup) {
            $byKind[$lookup->kind()->value] = $lookup;
        }

        $this->lookups = $byKind;
    }

    /**
     * Returns the row once answered, or null when there was nothing to do: no
     * such row (pruned since it was queued), not Pending (another job got to
     * it), lookups switched off, or no source for the kind.
     */
    public function resolve(SenderPictureKind $kind, string $subject): ?SenderPicture
    {
        $row = $this->pictures->findOneFor($kind, $subject);

        if (null === $row || SenderPictureStatus::Pending !== $row->status || false === $this->settings->lookupsEnabled()) {
            return null;
        }

        $lookup = $this->lookups[$kind->value] ?? null;

        if (null === $lookup) {
            return null;
        }

        try {
            $fetched = $lookup->lookup($subject);
        } catch (SenderPictureUnavailableException $exception) {
            $this->logger->info('SenderPictureResolver: nobody answered', [
                'kind'    => $kind->value,
                'subject' => $subject,
                'error'   => $exception->getMessage(),
            ]);

            $row->failed();
            $this->em->flush();

            return $row;
        }

        $stored = null === $fetched ? null : $this->image->normalize($fetched);

        if (null === $fetched || null === $stored) {
            $row->missing();
        } else {
            $row->found($this->store->store($stored['bytes'], $stored['extension']), mb_substr($fetched->origin, 0, 512));
        }

        $this->em->flush();

        return $row;
    }
}
