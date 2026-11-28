<?php

declare(strict_types=1);

namespace App\Infrastructure\Event\Subscriber;

use App\Jmap\State\StateManager;
use App\Repository\Jmap\ChangeLog\ChangeLogRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Marks the response to any request that changed the user's mail.
 *
 * `X-Mail-Changed: <sequence>`, read by assets/mail_writes.js, which re-reads
 * the sidebar counts when it sees it. This is the immediate half of what
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
 *
 * THE VALUE IS THE USER'S CHANGE SEQUENCE NOW (#807), where it used to be a
 * bare `1`. The same drain then nudges every open tab with `mail.changed` and
 * that sequence, and the mail list re-reads itself for a nudge it has not
 * already seen. This tab has: it made the change and drew its result from the
 * answer to its own request. With the number on that answer, the list can tell
 * its own write from somebody else's and does not fetch itself again after
 * every message opened (opening one marks it read). The change is flushed by
 * the time the response goes out, so the sequence read here includes it. One
 * query, on writing requests only.
 */
final readonly class MailChangedHeaderSubscriber implements EventSubscriberInterface
{
    public const string HEADER = 'X-Mail-Changed';

    public function __construct(
        private StateManager        $stateManager,
        private ChangeLogRepository $changeLog,
        private Security            $security,
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

        $user = $this->security->getUser();

        // A write by nobody signed in (a webhook, say) still changed mail;
        // there is just no tab whose sequence it could be.
        $event->getResponse()->headers->set(
            self::HEADER,
            null === $user ? '1' : (string) max(1, $this->changeLog->latestSequenceForUser($user)),
        );
    }
}
