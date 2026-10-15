<?php

declare(strict_types=1);

namespace App\Infrastructure\Event\Subscriber;

use App\Jmap\State\StateManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Marks the response to any request that changed the user's mail.
 *
 * `X-Mail-Changed: 1`, read by assets/mail_writes.js, which re-reads the
 * sidebar counts when it sees it. This is the immediate half of what
 * MailChangeNotifier does over the hub: the tab that made the change learns of
 * it from the answer to its own request, with no hub in between — so it works
 * on an install without one, and the number moves as the button is released
 * rather than a round trip later.
 *
 * It replaces a convention with a fact. The browser used to be told by each
 * controller that wrote calling announceWrite() after its fetch, and a write
 * path that did not — the whole composer, for one — left the badges stale.
 * Whether a request changed mail is known here without anyone having to say:
 * StateManager has something recorded, or it has not.
 *
 * Read before the drain, which JmapPushSubscriber does on terminate, after the
 * response has gone.
 */
final readonly class MailChangedHeaderSubscriber implements EventSubscriberInterface
{
    public const string HEADER = 'X-Mail-Changed';

    public function __construct(
        private StateManager $stateManager,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [ResponseEvent::class => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (false === $event->isMainRequest() || false === $this->stateManager->hasDirty()) {
            return;
        }

        $event->getResponse()->headers->set(self::HEADER, '1');
    }
}
