<?php

declare(strict_types=1);

namespace App\Service\Mail\SenderPicture;

use App\Domain\Enum\Mail\SenderPictureKind;
use App\Domain\Helper\PersonalMailDomains;
use App\Domain\Helper\SenderPictureSubject;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\ResolveSenderPictureMessage;
use App\Repository\Mail\SenderPicture\SenderPictureRepository;
use App\Repository\System\SenderPictureSettings\SenderPictureSettingsRepository;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues the sender picture lookups that are due for some addresses (#639).
 *
 * Every way a lookup starts comes through here — mail arriving
 * (SenderPicturesStep), a user switching a source on or an administrator
 * allowing lookups (QueueSenderPicturesHandler) — so "who may cause a request
 * to whom" is decided in one place:
 *
 *  - nothing at all while the administrator has lookups off;
 *  - a kind only for the users among $users who allow it — a newsletter that
 *    reaches one user who wants Gravatar and one who does not is looked up
 *    for the first, and the second never sees it (SenderPictures checks their
 *    own switches again when drawing);
 *  - a lookup only when SenderPictureRepository::claim() says it is due, so a
 *    burst of mail from one sender queues one job.
 *
 * The scheduled refresh claims its own rows and dispatches without passing
 * through here, because it has no users to ask about — the people who would
 * see the answer already said yes when the row was first claimed.
 */
final readonly class SenderPictureQueue
{
    public function __construct(
        private SenderPictureRepository         $pictures,
        private SenderPictureSettingsRepository $settings,
        private MessageBusInterface             $bus,
    ) {}

    /**
     * @param list<User>   $users     who received mail from these addresses
     * @param list<string> $addresses From addresses, as they came
     *
     * @return int how many lookups were queued
     */
    public function queue(array $users, array $addresses): int
    {
        if ([] === $users || [] === $addresses || false === $this->settings->lookupsEnabled()) {
            return 0;
        }

        $queued = 0;

        foreach (SenderPictureKind::lookups() as $kind) {
            $userIds = [];

            foreach ($users as $user) {
                if (null !== $user->id && true === $user->allowsSenderPicture($kind)) {
                    $userIds[] = $user->id;
                }
            }

            if ([] === $userIds) {
                continue;
            }

            $subjects = [];

            foreach ($addresses as $address) {
                $subject = SenderPictureSubject::of($kind, $address);

                // A personal provider has no website icon worth having, and
                // its row would only ever say so — WebsitePictureLookup refuses
                // it too, but not claiming it saves the row and the job.
                if (null === $subject || (SenderPictureKind::Website === $kind && true === PersonalMailDomains::contains($subject))) {
                    continue;
                }

                $subjects[] = $subject;
            }

            foreach ($this->pictures->claim($kind, $subjects) as $subject) {
                $this->bus->dispatch(new ResolveSenderPictureMessage($kind->value, $subject, array_values(array_unique($userIds))));
                ++$queued;
            }
        }

        return $queued;
    }
}
