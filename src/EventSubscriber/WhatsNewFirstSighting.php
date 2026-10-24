<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User\User;
use App\Service\System\WhatsNew\WhatsNew;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Records the running release as where a new person starts, on their first
 * request (#165).
 *
 * What's new opens after an update, never on an install. Everybody who
 * existed when the dialog shipped got 0.3.1 from Version20261016090000; this
 * covers everyone created since — the administrator of a fresh install, a user
 * an administrator adds, somebody single sign-on creates. One place that sees
 * all of them, rather than one line in each of the paths that create a user,
 * which a fifth path would not have.
 *
 * Writes once per person and then never again: after the first request the
 * key is there and startIfNew() returns before touching anything. On a
 * `main` or commit build the running release is the newest one in its
 * changelog (WhatsNew::running()), so somebody created there starts on that.
 */
final readonly class WhatsNewFirstSighting implements EventSubscriberInterface
{
    public function __construct(
        private WhatsNew               $whatsNew,
        private Security               $security,
        private EntityManagerInterface $em,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After the firewall (8), so the user is known.
        return [RequestEvent::class => ['onRequest', 4]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (false === $event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();

        if (false === $user instanceof User || false === $this->whatsNew->startIfNew($user)) {
            return;
        }

        $this->em->flush();
    }
}
