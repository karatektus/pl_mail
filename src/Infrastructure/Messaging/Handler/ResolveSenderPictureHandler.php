<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Mail\SenderPictureKind;
use App\Domain\Enum\Mail\SenderPictureStatus;
use App\Infrastructure\Messaging\Message\ResolveSenderPictureMessage;
use App\Service\Mail\SenderPicture\SenderPictureNotifier;
use App\Service\Mail\SenderPicture\SenderPictureResolver;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Does one sender picture lookup and, when it found a picture, puts it on the
 * pages of the users who were waiting for it (#639).
 *
 * The notification goes out whenever the answer is a picture, not only when
 * the picture is new. A re-check that finds the same Gravatar sends a
 * harmless update its pages already match; telling "new" from "the same" would
 * need the old file name carried across the lookup, for no visible gain.
 */
#[AsMessageHandler]
final readonly class ResolveSenderPictureHandler
{
    public function __construct(
        private SenderPictureResolver $resolver,
        private SenderPictureNotifier $notifier,
    ) {}

    public function __invoke(ResolveSenderPictureMessage $message): void
    {
        $kind = SenderPictureKind::tryFrom($message->kind);

        if (null === $kind || false === $kind->isLookup()) {
            return;
        }

        $picture = $this->resolver->resolve($kind, $message->subject);

        if (null !== $picture && SenderPictureStatus::Found === $picture->status) {
            $this->notifier->found($picture, $message->userIds);
        }
    }
}
