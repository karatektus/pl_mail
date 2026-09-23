<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A short name for every request, so an error the user saw can be found.
 *
 * "Something went wrong" in a toast is only half an answer; the other half is
 * being able to say which log entry it was without comparing clocks. So each
 * request gets eight hex characters, recorded on its log entries (see
 * DoctrineLogHandler::describeRequest()) and handed back in `X-Request-Id`,
 * where request_errors.js reads it into the toast.
 *
 * A proxy's own id is kept when it sends one in a shape worth keeping, so a
 * trace through Nginx Proxy Manager and plMail can share a name. Anything else
 * in that header is ignored rather than trusted: it ends up in the log, and a
 * log is not a place for text a client chose.
 */
final class RequestIdSubscriber implements EventSubscriberInterface
{
    public const string ATTRIBUTE = '_request_id';
    public const string HEADER    = 'X-Request-Id';

    public static function getSubscribedEvents(): array
    {
        return [
            // Early, so anything that logs while handling the request has it.
            KernelEvents::REQUEST  => ['onRequest', 4096],
            KernelEvents::RESPONSE => ['onResponse', -4096],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (false === $event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $given   = (string) $request->headers->get(self::HEADER, '');

        $request->attributes->set(
            self::ATTRIBUTE,
            1 === preg_match('/^[A-Za-z0-9-]{8,64}$/', $given) ? $given : bin2hex(random_bytes(4)),
        );
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (false === $event->isMainRequest()) {
            return;
        }

        $id = $event->getRequest()->attributes->get(self::ATTRIBUTE);

        if (true === is_string($id)) {
            $event->getResponse()->headers->set(self::HEADER, $id);
        }
    }
}
