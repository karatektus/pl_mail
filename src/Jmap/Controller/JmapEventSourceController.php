<?php

declare(strict_types=1);

namespace App\Jmap\Controller;

use App\Entity\User;
use App\Jmap\Protocol\StateChangeBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * EventSource push (RFC 8620 §7.3). Advertised as eventSourceUrl in the
 * Session object.
 *
 * Emits a StateChange whenever any tracked object type for any of the user's
 * accounts moves, plus periodic pings so proxies do not drop an idle
 * connection.
 *
 * IMPORTANT — this holds a PHP worker for the whole life of the connection.
 * Under FrankenPHP that is a hard capacity limit: N connected clients means N
 * occupied workers, and once they are all taken the app stops answering
 * ordinary requests. Hence the deliberately short MAX_LIFETIME — clients
 * reconnect automatically, and the disconnect costs nothing but a round trip.
 * Background delivery belongs on PushSubscription/Web Push, not here.
 */
final class JmapEventSourceController extends AbstractController
{
    /** How long a single connection is allowed to live before the client must reconnect. */
    private const int MAX_LIFETIME_SECONDS = 300;

    /** How often the change log is checked. */
    private const int POLL_INTERVAL_SECONDS = 3;

    private const int DEFAULT_PING_SECONDS = 30;
    private const int MIN_PING_SECONDS = 5;

    public function __construct(
        private readonly StateChangeBuilder $stateChangeBuilder,
    ) {
    }

    #[Route('/jmap/eventsource', name: 'jmap_eventsource', methods: ['GET'])]
    public function eventSource(Request $request, #[CurrentUser] User $user): Response
    {
        $ping = $this->pingInterval($request);
        // "closeafter=state" asks for one StateChange and then a close, which is
        // how a client cheaply resyncs without holding a connection open.
        $closeAfterState = 'state' === $request->query->get('closeafter');

        $response = new StreamedResponse(function () use ($user, $ping, $closeAfterState): void {
            $this->emit($user, $ping, $closeAfterState);
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        // Tells nginx and friends not to buffer, which would defeat the point.
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    private function emit(User $user, int $ping, bool $closeAfterState): void
    {
        // php.ini-development caps a request at 30 seconds, and FrankenPHP
        // counts that as wall clock — so every connection died inside the
        // sleep() below with a MaxExecutionTimeError, logged CRITICAL, once per
        // client reconnect. The lifetime this stream is designed for is the one
        // that should bound it; the margin leaves room for the final write.
        set_time_limit(self::MAX_LIFETIME_SECONDS + 30);

        $started = time();
        $lastPing = $started;
        $known = $this->stateChangeBuilder->snapshot($user);

        // The first event carries the current state, so a client that just
        // connected knows where it stands without a separate round trip.
        $this->send('state', $this->stateChangeBuilder->format($known));

        if (true === $closeAfterState) {
            return;
        }

        while (time() - $started < self::MAX_LIFETIME_SECONDS) {
            if (true === connection_aborted()) {
                return;
            }

            sleep(self::POLL_INTERVAL_SECONDS);

            $current = $this->stateChangeBuilder->snapshot($user);
            $changed = $this->stateChangeBuilder->diff($known, $current);

            if (count($changed) > 0) {
                $this->send('state', $this->stateChangeBuilder->format($changed));
                $known = $current;
                $lastPing = time();

                continue;
            }

            if (time() - $lastPing >= $ping) {
                $this->send('ping', ['interval' => $ping]);
                $lastPing = time();
            }
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function send(string $event, array $data): void
    {
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($data, JSON_THROW_ON_ERROR)."\n\n";

        // Both are needed: PHP's buffer and whatever the SAPI added on top.
        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

    private function pingInterval(Request $request): int
    {
        $ping = $request->query->getInt('ping', self::DEFAULT_PING_SECONDS);

        if ($ping < self::MIN_PING_SECONDS) {
            return self::DEFAULT_PING_SECONDS;
        }

        return $ping;
    }
}
