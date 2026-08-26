<?php

declare(strict_types=1);

namespace App\Infrastructure\Event\Subscriber;

use App\Domain\Helper\ThrowableSeverity;
use App\Entity\User\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Attaches the Mercure subscriber cookie to HTML responses for a signed-in user.
 *
 * The hub authorizes subscribers by JWT, and this is where the browser gets one
 * — without it the stream is refused and the mail list never updates by itself.
 *
 * It lives here rather than in the layout, where `mercure(topics, {subscribe:
 * …})` would mint it, for one reason: that helper throws when the hub sits on a
 * different second-level domain than the request, and a throw inside a template
 * takes the entire page down with it. A hub on another domain is a legitimate
 * deployment — it just cannot be given a first-party cookie — and the honest
 * outcome there is a page that renders with live updates unavailable, not a 500.
 * PHPUnit found this immediately: its client requests as `localhost` while the
 * test hub is on `127.0.0.1`, and every page-rendering test turned 500.
 *
 * Failures are logged once at info, not warning: for anyone running an external
 * hub this is expected and permanent, and a warning on every page view would be
 * noise in a log they cannot act on.
 */
final readonly class MercureCookieSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Authorization $authorization,
        private TokenStorageInterface $tokenStorage,
        private LoggerInterface $logger,
    )
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onKernelResponse'];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();

        // Only documents. A cookie repeated on every asset, Turbo fragment and
        // JSON response is bytes on the wire for no benefit — the browser only
        // needs it before it opens the stream, which happens on a page load.
        if (!str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();

        if (!$user instanceof User) {
            return;
        }

        // The user's own topic and nothing else: the id comes from the session,
        // so a cookie can never authorize somebody else's mail.
        $topics = ['mail/user/'.$user->id];

        try {
            $response->headers->setCookie(
                $this->authorization->createCookie($event->getRequest(), $topics),
            );
        } catch (\Throwable $exception) {
            $this->logger->log(ThrowableSeverity::level($exception), 'Could not issue a Mercure subscriber cookie; live updates will be unavailable.',
                ['reason' => $exception->getMessage()],
            );
        }
    }
}
