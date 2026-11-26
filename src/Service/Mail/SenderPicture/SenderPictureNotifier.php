<?php

declare(strict_types=1);

namespace App\Service\Mail\SenderPicture;

use App\Domain\Enum\Mail\SenderPictureKind;
use App\Domain\Helper\SenderPictureReference;
use App\Domain\Helper\SenderPictureSubject;
use App\Domain\Helper\ThrowableSeverity;
use App\Entity\Mail\SenderPicture;
use App\Infrastructure\Mercure\UserUpdate;
use App\Repository\User\User\UserRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Puts a picture a lookup just found onto rows already on screen (#639).
 *
 * Without it, a sender's picture appears the next time the list is drawn —
 * which, for mail that has just arrived, is often never in the session that
 * saw it arrive. The job is typically done a second or two after the row was
 * drawn with initials.
 *
 * **Carries the answer, not a nudge.** ThreadSummaryNotifier explains why most
 * publishers here send "go and look"; that reasoning is about re-rendering
 * markup that has rules of its own. This is one URL. Asking the server for it
 * again would be a request per open tab to learn a string the job already
 * has.
 *
 * **Names no address.** The payload says WHICH rows by a key the page already
 * carries — a hash of the address, or the domain (SenderPictureSubject) — and
 * the URL is an opaque reference. `mail/user/{id}` is private (UserUpdate), so
 * this is belt and braces, not the protection.
 *
 * **Only to users who would see it.** A user who has not switched this kind
 * on gets nothing, because their page would only have to throw it away —
 * worse, a page that took it would show what the user turned off.
 */
final readonly class SenderPictureNotifier
{
    public function __construct(
        private HubInterface          $hub,
        private UserRepository        $users,
        private UrlGeneratorInterface $urls,
        private LoggerInterface       $logger,
    ) {}

    /**
     * @param list<int> $userIds
     */
    public function found(SenderPicture $picture, array $userIds): void
    {
        if ([] === $userIds || null === $picture->id || null === $picture->file) {
            return;
        }

        $key = self::keyFor($picture);

        if (null === $key) {
            return;
        }

        $data = (string) json_encode([
            'type'  => 'sender-picture.found',
            'kind'  => $picture->kind->value,
            'key'   => $key,
            'rank'  => $picture->kind->rank(),
            'url'   => $this->urls->generate('app_sender_picture', [
                'reference' => SenderPictureReference::for(SenderPictureReference::STORED, $picture->id, $picture->file),
            ]),
        ]);

        foreach ($this->users->findBy(['id' => $userIds]) as $user) {
            if (null === $user->id || false === $user->allowsSenderPicture($picture->kind)) {
                continue;
            }

            try {
                $this->hub->publish(UserUpdate::create(
                    topics: [sprintf('mail/user/%d', $user->id)],
                    data: $data,
                ));
            } catch (\Throwable $exception) {
                // The doorbell, not the delivery: the picture is stored, and
                // the next render shows it. Failing the job over this would
                // only have Messenger fetch the picture a second time.
                $this->logger->log(
                    ThrowableSeverity::level($exception, LogLevel::WARNING),
                    'SenderPictureNotifier: publish failed',
                    ['userId' => $user->id, 'error' => $exception->getMessage(), 'exception' => $exception],
                );
            }
        }
    }

    /**
     * The key the page's markup carries for rows this picture belongs to —
     * see SenderPictures::markupKeys(), which must
     * write the same thing.
     */
    private static function keyFor(SenderPicture $picture): ?string
    {
        return match ($picture->kind) {
            SenderPictureKind::Hashed                          => SenderPictureSubject::addressKey($picture->subject),
            SenderPictureKind::Bimi, SenderPictureKind::Website => $picture->subject,
            SenderPictureKind::LocalUser, SenderPictureKind::Contact => null,
        };
    }
}
