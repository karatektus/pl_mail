<?php

declare(strict_types=1);

namespace App\Service\JmapClient;

use App\Domain\Enum\PushHealth;
use App\Domain\Exception\JmapApiException;
use App\Domain\Interface\PushSubscriptionManagerInterface;
use App\Entity\Mail\Account;
use App\Infrastructure\JmapClient\JmapClient;
use App\Service\Setup\PublicUrlSetting;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Push for JMAP accounts: a PushSubscription (RFC 8620 §7.2) where the server
 * can reach plMail, the EventSource stream where it cannot.
 *
 * ## Two channels, one switch
 *
 * The account's push switch means "changes arrive as they happen", and for a
 * JMAP account that is always possible: app:jmap:listen holds an EventSource
 * stream open for every account with push on and needs nothing configured.
 * The webhook is the better channel when there is one — no connection held
 * open, no process to keep alive — so where the install has a public HTTPS
 * address this registers a PushSubscription as well, and once the server has
 * verified it the listener leaves that account alone (see
 * Account::hasLiveJmapPushSubscription()). A subscription the server never
 * verifies, because plMail is not reachable from where the server is, simply
 * leaves the account on the stream.
 *
 * So subscribe() succeeds whenever push is on, whether or not a webhook could
 * be registered, and isConfigured() is always true: unlike Gmail and Graph,
 * a JMAP account does not need the administrator to have done anything.
 *
 * ## Verification
 *
 * The server proves it can reach the callback by POSTing a PushVerification
 * there; JmapPushController hands the code to confirm(), which echoes it back
 * with PushSubscription/set. Nothing is pushed before that.
 *
 * ## Authenticity
 *
 * The callback URL carries a 256-bit token minted per subscription, compared
 * in constant time — the clientState idea of Graph's subscriptions, carried in
 * the path because JMAP has no field for it. The worst a forged POST can do
 * is queue a sync, which reads the server's own changes and is idempotent.
 */
final readonly class JmapPushSubscriptionManager implements PushSubscriptionManagerInterface
{
    /** How long a subscription is asked for. Servers may grant less. */
    private const int LIFETIME_DAYS = 7;

    /** Renew once the remaining lifetime drops below this. */
    private const int RENEW_THRESHOLD_HOURS = 48;

    public function __construct(
        private JmapClient             $jmap,
        private UrlGeneratorInterface  $urlGenerator,
        private PublicUrlSetting       $publicUrl,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {
    }

    public function supports(Account $account): bool
    {
        return true === $account->isJmap();
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function messageKey(): string
    {
        return 'jmap';
    }

    public function expiresAt(Account $account): ?DateTimeImmutable
    {
        $subscription = $account->jmapPushSubscription;

        return null === $subscription ? null : new DateTimeImmutable('@' . $subscription['expires']);
    }

    /**
     * Active whenever push is on: one of the two channels is carrying it, and
     * the stream is the one that needs nothing to work. A webhook that lapsed
     * or was never verified is not a fault the user can see — the stream
     * takes the account back — so it is not reported as one.
     */
    public function health(Account $account): PushHealth
    {
        return true === $account->pushEnabled ? PushHealth::Active : PushHealth::Inactive;
    }

    public function subscribe(Account $account): bool
    {
        if (false === $this->supports($account) || true !== $account->pushEnabled) {
            return false;
        }

        if (true === $this->isPubliclyRoutable()) {
            $this->unsubscribe($account);
            $this->register($account);
        }

        return true;
    }

    public function renew(Account $account): bool
    {
        $subscription = $account->jmapPushSubscription;

        if (null === $subscription || false === $subscription['verified']) {
            return $this->subscribe($account);
        }

        $expires = new DateTimeImmutable(sprintf('+%d days', self::LIFETIME_DAYS));

        try {
            $result = $this->jmap->call($account, [], [
                ['PushSubscription/set', ['update' => [$subscription['id'] => ['expires' => $expires->format('Y-m-d\TH:i:s\Z')]]], 'p'],
            ])->get('p', 'PushSubscription/set');
        } catch (JmapApiException $e) {
            $this->logger->warning('JmapPushSubscriptionManager: renewal failed, registering again', [
                'accountId' => $account->id,
                'error'     => $e->getMessage(),
            ]);

            return $this->subscribe($account);
        }

        if (true === isset($result['notUpdated'][$subscription['id']])) {
            // Gone on the server: the ordinary end of a subscription nobody
            // renewed in time. A fresh one is the answer.
            $account->jmapPushSubscription = null;
            $this->em->flush();

            return $this->subscribe($account);
        }

        $granted = $result['updated'][$subscription['id']]['expires'] ?? null;

        $account->jmapPushSubscription = ['expires' => $this->timestamp($granted) ?? $expires->getTimestamp()] + $subscription;
        $this->em->flush();

        return true;
    }

    /**
     * Destroy the subscription on the server and forget it here.
     *
     * Remote failures are swallowed, as Graph's are: an unrenewed
     * subscription expires on its own, and turning push off must not fail
     * over a server that is down.
     */
    public function unsubscribe(Account $account): void
    {
        $subscription = $account->jmapPushSubscription;

        if (null === $subscription) {
            return;
        }

        try {
            $this->jmap->call($account, [], [
                ['PushSubscription/set', ['destroy' => [$subscription['id']]], 'p'],
            ]);
        } catch (JmapApiException $e) {
            $this->logger->info('JmapPushSubscriptionManager: teardown failed, letting it expire', [
                'accountId' => $account->id,
                'error'     => $e->getMessage(),
            ]);
        }

        $account->jmapPushSubscription = null;
        $this->em->flush();
    }

    public function needsRenewal(Account $account): bool
    {
        if (true !== $account->pushEnabled || false === $this->isPubliclyRoutable()) {
            return false;
        }

        $subscription = $account->jmapPushSubscription;

        if (null === $subscription) {
            return true;
        }

        return $subscription['expires'] <= new DateTimeImmutable(sprintf('+%d hours', self::RENEW_THRESHOLD_HOURS))->getTimestamp();
    }

    /**
     * Echo a PushVerification's code back, which is what turns the server's
     * pushing on (RFC 8620 §7.2.2).
     *
     * Only for the subscription this account holds: a verification for any
     * other id is a subscription plMail has since replaced, and confirming it
     * would leave the server pushing twice.
     */
    public function confirm(Account $account, string $subscriptionId, string $code): bool
    {
        $subscription = $account->jmapPushSubscription;

        if (null === $subscription || $subscription['id'] !== $subscriptionId) {
            return false;
        }

        try {
            $result = $this->jmap->call($account, [], [
                ['PushSubscription/set', ['update' => [$subscriptionId => ['verificationCode' => $code]]], 'p'],
            ])->get('p', 'PushSubscription/set');
        } catch (JmapApiException $e) {
            $this->logger->warning('JmapPushSubscriptionManager: verification could not be confirmed', [
                'accountId' => $account->id,
                'error'     => $e->getMessage(),
            ]);

            return false;
        }

        if (true === isset($result['notUpdated'][$subscriptionId])) {
            return false;
        }

        $account->jmapPushSubscription = ['verified' => true] + $subscription;
        $this->em->flush();

        $this->logger->info('JmapPushSubscriptionManager: the server will push to the webhook', ['accountId' => $account->id]);

        return true;
    }

    /**
     * Whether a POST to the callback carries this account's token.
     */
    public function isAuthentic(Account $account, string $token): bool
    {
        $expected = $account->jmapPushSubscription['token'] ?? '';

        return '' !== $expected && true === hash_equals($expected, $token);
    }

    private function register(Account $account): void
    {
        $token   = bin2hex(random_bytes(32));
        $expires = new DateTimeImmutable(sprintf('+%d days', self::LIFETIME_DAYS));
        $url     = rtrim((string) $this->publicUrl->current(), '/') . $this->urlGenerator->generate('app_jmap_push', [
            'accountId' => (int) $account->id,
            'token'     => $token,
        ]);

        try {
            $result = $this->jmap->call($account, [], [
                ['PushSubscription/set', ['create' => ['p' => [
                    'deviceClientId' => sprintf('plmail-%d', (int) $account->id),
                    'url'            => $url,
                    'expires'        => $expires->format('Y-m-d\TH:i:s\Z'),
                    'types'          => null,
                ]]], 'p'],
            ])->get('p', 'PushSubscription/set');
        } catch (JmapApiException $e) {
            $this->logger->info('JmapPushSubscriptionManager: no webhook, the stream carries push', [
                'accountId' => $account->id,
                'error'     => $e->getMessage(),
            ]);

            return;
        }

        $id = $result['created']['p']['id'] ?? null;

        if (false === is_string($id)) {
            $this->logger->info('JmapPushSubscriptionManager: the server would not take a webhook, the stream carries push', [
                'accountId' => $account->id,
                'type'      => $result['notCreated']['p']['type'] ?? null,
            ]);

            return;
        }

        // Written before the server's verification can arrive, so the
        // controller finds the token and the id it is asked to confirm.
        $account->jmapPushSubscription = [
            'id'       => $id,
            'verified' => false,
            'expires'  => $this->timestamp($result['created']['p']['expires'] ?? null) ?? $expires->getTimestamp(),
            'token'    => $token,
        ];

        $this->em->flush();
    }

    /**
     * The rule GraphSubscriptionManager states: a callback the server can
     * reach is an HTTPS one on a public name, and checking here turns an
     * unverifiable subscription into a log line that says why.
     */
    private function isPubliclyRoutable(): bool
    {
        $base = trim((string) $this->publicUrl->current());

        if (false === str_starts_with($base, 'https://')) {
            return false;
        }

        $host = parse_url($base, PHP_URL_HOST);

        return true === is_string($host) && false === in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private function timestamp(mixed $utc): ?int
    {
        if (false === is_string($utc) || '' === $utc) {
            return null;
        }

        try {
            return new DateTimeImmutable($utc)->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }
}
