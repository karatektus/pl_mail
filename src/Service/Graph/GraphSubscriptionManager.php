<?php

declare(strict_types=1);

namespace App\Service\Graph;

use App\Domain\Enum\PushHealth;
use App\Domain\Exception\GraphApiException;
use App\Domain\Interface\PushSubscriptionManagerInterface;
use App\Entity\Mail\Account;
use App\Service\Mail\GraphApiClient;
use App\Service\Setup\PublicUrlSetting;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Owns the lifecycle of Graph change subscriptions (push).
 *
 * Push is opt-in per account and always degrades to polling. Graph validates
 * the notification URL synchronously when the subscription is created — it
 * POSTs a validationToken and expects the raw token echoed back inside ten
 * seconds — so a self-hosted instance that is not actually reachable from the
 * internet fails here, loudly but harmlessly, and app:mail:sync keeps working.
 *
 * Reverse proxies are the normal deployment, so the notification URL is built
 * from an explicitly configured public base URL rather than from the incoming
 * request. Deriving it from the request would produce an internal hostname (or
 * http:// after TLS termination) and Graph would reject the subscription with
 * a validation failure that is genuinely unpleasant to diagnose.
 */
final readonly class GraphSubscriptionManager implements PushSubscriptionManagerInterface
{
    /**
     * Graph caps /me/messages subscriptions just under three days
     * (4230 minutes). Renew comfortably inside that.
     */
    private const int LIFETIME_MINUTES = 4200;

    /** Renew once the remaining lifetime drops below this. */
    public const int RENEW_THRESHOLD_MINUTES = 720;

    public function __construct(
        private GraphApiClient         $apiClient,
        private UrlGeneratorInterface  $urlGenerator,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
        // Resolved per call, not injected as a string: the workers that build
        // subscriptions are long-running, and the URL is typically saved from
        // the setup screen after they have booted.
        private PublicUrlSetting       $publicUrl,
    ) {}

    /**
     * Create a subscription for an account, replacing any existing one.
     *
     * Returns false when push could not be established — the caller should
     * leave the account on polling rather than treat it as an error.
     */
    public function supports(Account $account): bool
    {
        return $account->isMicrosoft();
    }

    public function isConfigured(): bool
    {
        return $this->isPubliclyRoutable();
    }

    public function messageKey(): string
    {
        return 'microsoft';
    }

    public function expiresAt(Account $account): ?DateTimeImmutable
    {
        return $account->graphSubscriptionExpiresAt;
    }

    /**
     * Graph validates the notification URL synchronously at subscribe time, so
     * there is no equivalent of Gmail's silent failure mode: a subscription
     * that exists is a subscription that was reachable. Degraded therefore only
     * covers a registration that has since lapsed — Graph will not revive it,
     * and the renewal command has to recreate it.
     */
    public function health(Account $account): PushHealth
    {
        if (true !== $account->pushEnabled) {
            return PushHealth::Inactive;
        }

        $subscriptionId = $account->graphSubscriptionId;

        if (null === $subscriptionId || '' === $subscriptionId) {
            return PushHealth::Inactive;
        }

        $expiresAt = $account->graphSubscriptionExpiresAt;

        // Lapsed rather than Degraded, and the distinction is real here too: a
        // Graph subscription past its expiry means renewal did not run, which
        // is a scheduler problem. Graph has no equivalent of Gmail's Degraded —
        // it validates the notification URL synchronously at subscribe time, so
        // a subscription that exists and has not expired is one Microsoft
        // confirmed it can reach.
        if (null === $expiresAt || $expiresAt <= new DateTimeImmutable()) {
            return PushHealth::Lapsed;
        }

        return PushHealth::Active;
    }

    public function subscribe(Account $account): bool
    {
        if (false === $this->supports($account)) {
            return false;
        }

        if (true !== $account->pushEnabled) {
            return false;
        }

        if (false === $this->isPubliclyRoutable()) {
            $this->logger->warning('GraphSubscriptionManager: no usable public base URL, staying on polling', [
                'accountId'     => $account->id,
                'publicBaseUrl' => $this->publicUrl->current() ?? '',
            ]);

            return false;
        }

        // Drop the old one first: Graph allows several subscriptions over the
        // same resource, and orphaned ones keep delivering until they lapse.
        $this->unsubscribe($account);

        $clientState = bin2hex(random_bytes(32));
        $expiresAt   = new DateTimeImmutable(sprintf('+%d minutes', self::LIFETIME_MINUTES));

        try {
            $subscription = $this->apiClient->createSubscription(
                $account,
                $this->notificationUrl(),
                $this->lifecycleUrl(),
                $clientState,
                $expiresAt,
            );
        } catch (\Throwable $e) {
            $this->logger->error('GraphSubscriptionManager: subscription failed, falling back to polling', [
                'accountId' => $account->id,
                'error'     => $e->getMessage(),
            ]);

            return false;
        }

        $subscriptionId = (string) ($subscription['id'] ?? '');

        if ('' === $subscriptionId) {
            return false;
        }

        $account->graphSubscriptionId = $subscriptionId;
        $account->graphSubscriptionClientState = $clientState;
        $account->graphSubscriptionExpiresAt = $this->parseExpiry($subscription) ?? $expiresAt;

        $this->em->flush();

        $this->logger->info('GraphSubscriptionManager: subscribed', [
            'accountId'      => $account->id,
            'subscriptionId' => $subscriptionId,
            'expiresAt'      => $account->graphSubscriptionExpiresAt->format(\DATE_ATOM),
        ]);

        return true;
    }

    /**
     * Extend an existing subscription. Falls back to creating a fresh one if
     * renewal fails — an expired subscription cannot be revived.
     */
    public function renew(Account $account): bool
    {
        $subscriptionId = $account->graphSubscriptionId;

        if (null === $subscriptionId || '' === $subscriptionId) {
            return $this->subscribe($account);
        }

        $expiresAt = new DateTimeImmutable(sprintf('+%d minutes', self::LIFETIME_MINUTES));

        try {
            $subscription = $this->apiClient->renewSubscription($account, $subscriptionId, $expiresAt);
        } catch (\Throwable $e) {
            // A 404 means Microsoft no longer has it, which is the ordinary end
            // of a subscription's life rather than a fault: there is nothing to
            // hand back and nothing an admin can do. Anything else — throttling,
            // a 5xx, a dropped connection — says the registration may well
            // still be live, and THAT is the case that has to hand it back.
            //
            // It used to clear local state here and let subscribe() do the
            // teardown. subscribe() returns early when the id is missing, so
            // the delete never went out: every renewal that failed for a
            // transient reason left Microsoft holding a live subscription
            // plMail had forgotten. It then delivered notifications for an id
            // no account matched — "GraphNotification: unknown subscription",
            // once an hour for up to three days, over something nobody could
            // act on because the id was gone from the only record of it.
            //
            // unsubscribe() is called here rather than left to subscribe()
            // because subscribe() bails before its own teardown when there is
            // no public URL. Handing the old registration back matters even
            // when a new one cannot be built.
            if (true === $this->isAlreadyGone($e)) {
                $this->logger->info('GraphSubscriptionManager: subscription had lapsed, recreating', [
                    'accountId'      => $account->id,
                    'subscriptionId' => $subscriptionId,
                ]);

                $this->clearLocalState($account);
            } else {
                $this->logger->warning('GraphSubscriptionManager: renewal failed, handing it back and recreating', [
                    'accountId' => $account->id,
                    'error'     => $e->getMessage(),
                ]);

                $this->unsubscribe($account);
            }

            return $this->subscribe($account);
        }

        $account->graphSubscriptionExpiresAt = $this->parseExpiry($subscription) ?? $expiresAt;
        $this->em->flush();

        return true;
    }

    /**
     * Remove the subscription both remotely and locally. Remote errors are
     * swallowed — a subscription we can no longer delete will lapse on its own
     * within three days, and blocking account deletion on it would be worse.
     */
    public function unsubscribe(Account $account): void
    {
        $subscriptionId = $account->graphSubscriptionId;

        if (null === $subscriptionId || '' === $subscriptionId) {
            return;
        }

        try {
            $this->apiClient->deleteSubscription($account, $subscriptionId);
        } catch (\Throwable $e) {
            $this->logger->info('GraphSubscriptionManager: teardown failed, letting it lapse', [
                'accountId' => $account->id,
                'error'     => $e->getMessage(),
            ]);
        }

        $this->clearLocalState($account);
        $this->em->flush();
    }

    public function needsRenewal(Account $account): bool
    {
        if (true !== $account->pushEnabled) {
            return false;
        }

        $expiresAt = $account->graphSubscriptionExpiresAt;

        if (null === $expiresAt) {
            return true;
        }

        $threshold = new DateTimeImmutable(sprintf('+%d minutes', self::RENEW_THRESHOLD_MINUTES));

        return $expiresAt <= $threshold;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Does this failure mean Microsoft has already let the subscription go?
     *
     * Only a 404 does. Everything else has to be treated as "it may still be
     * there", because the cost of being wrong runs one way: assuming it is gone
     * when it is not orphans a live registration, while assuming it is there
     * when it is not costs one DELETE that answers 404 and is swallowed.
     */
    private function isAlreadyGone(\Throwable $e): bool
    {
        return $e instanceof GraphApiException && 404 === $e->getStatus();
    }

    private function clearLocalState(Account $account): void
    {
        $account->graphSubscriptionId = null;
        $account->graphSubscriptionClientState = null;
        $account->graphSubscriptionExpiresAt = null;
    }

    private function notificationUrl(): string
    {
        return $this->absolute('app_graph_notification');
    }

    private function lifecycleUrl(): string
    {
        return $this->absolute('app_graph_lifecycle');
    }

    /**
     * Build against the configured public base URL, not the request context —
     * see the class docblock on reverse proxies.
     */
    private function absolute(string $route): string
    {
        $path = $this->urlGenerator->generate($route);

        return rtrim((string) $this->publicUrl->current(), '/') . $path;
    }

    /**
     * Graph refuses any notification URL that is not HTTPS, and will never
     * reach localhost. Catching that here turns a confusing remote validation
     * error into a clear local log line.
     */
    private function isPubliclyRoutable(): bool
    {
        $base = trim((string) $this->publicUrl->current());

        if ('' === $base) {
            return false;
        }

        if (false === str_starts_with($base, 'https://')) {
            return false;
        }

        $host = parse_url($base, PHP_URL_HOST);

        if (false === is_string($host)) {
            return false;
        }

        return false === in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * @param array<string,mixed> $subscription
     */
    private function parseExpiry(array $subscription): ?DateTimeImmutable
    {
        $raw = $subscription['expirationDateTime'] ?? null;

        if (false === is_string($raw) || '' === $raw) {
            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
