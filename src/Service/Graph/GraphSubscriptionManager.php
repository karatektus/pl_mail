<?php

declare(strict_types=1);

namespace App\Service\Graph;

use App\Domain\Enum\PushHealth;
use App\Domain\Interface\PushSubscriptionManagerInterface;
use App\Entity\Account;
use App\Service\Mail\GraphApiClient;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        #[Autowire('env: APP_PUBLIC_URL')]
        private string                 $publicBaseUrl,
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

    public function expiresAt(Account $account): ?DateTimeImmutable
    {
        return $account->getGraphSubscriptionExpiresAt();
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
        if (true !== $account->isPushEnabled()) {
            return PushHealth::Inactive;
        }

        $subscriptionId = $account->getGraphSubscriptionId();

        if (null === $subscriptionId || '' === $subscriptionId) {
            return PushHealth::Inactive;
        }

        $expiresAt = $account->getGraphSubscriptionExpiresAt();

        if (null === $expiresAt || $expiresAt <= new DateTimeImmutable()) {
            return PushHealth::Degraded;
        }

        return PushHealth::Active;
    }

    public function subscribe(Account $account): bool
    {
        if (false === $this->supports($account)) {
            return false;
        }

        if (true !== $account->isPushEnabled()) {
            return false;
        }

        if (false === $this->isPubliclyRoutable()) {
            $this->logger->warning('GraphSubscriptionManager: no usable public base URL, staying on polling', [
                'accountId'     => $account->getId(),
                'publicBaseUrl' => $this->publicBaseUrl,
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
                'accountId' => $account->getId(),
                'error'     => $e->getMessage(),
            ]);

            return false;
        }

        $subscriptionId = (string) ($subscription['id'] ?? '');

        if ('' === $subscriptionId) {
            return false;
        }

        $account
            ->setGraphSubscriptionId($subscriptionId)
            ->setGraphSubscriptionClientState($clientState)
            ->setGraphSubscriptionExpiresAt($this->parseExpiry($subscription) ?? $expiresAt);

        $this->em->flush();

        $this->logger->info('GraphSubscriptionManager: subscribed', [
            'accountId'      => $account->getId(),
            'subscriptionId' => $subscriptionId,
            'expiresAt'      => $account->getGraphSubscriptionExpiresAt()?->format(\DATE_ATOM),
        ]);

        return true;
    }

    /**
     * Extend an existing subscription. Falls back to creating a fresh one if
     * renewal fails — an expired subscription cannot be revived.
     */
    public function renew(Account $account): bool
    {
        $subscriptionId = $account->getGraphSubscriptionId();

        if (null === $subscriptionId || '' === $subscriptionId) {
            return $this->subscribe($account);
        }

        $expiresAt = new DateTimeImmutable(sprintf('+%d minutes', self::LIFETIME_MINUTES));

        try {
            $subscription = $this->apiClient->renewSubscription($account, $subscriptionId, $expiresAt);
        } catch (\Throwable $e) {
            $this->logger->warning('GraphSubscriptionManager: renewal failed, recreating', [
                'accountId' => $account->getId(),
                'error'     => $e->getMessage(),
            ]);

            $this->clearLocalState($account);

            return $this->subscribe($account);
        }

        $account->setGraphSubscriptionExpiresAt($this->parseExpiry($subscription) ?? $expiresAt);
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
        $subscriptionId = $account->getGraphSubscriptionId();

        if (null === $subscriptionId || '' === $subscriptionId) {
            return;
        }

        try {
            $this->apiClient->deleteSubscription($account, $subscriptionId);
        } catch (\Throwable $e) {
            $this->logger->info('GraphSubscriptionManager: teardown failed, letting it lapse', [
                'accountId' => $account->getId(),
                'error'     => $e->getMessage(),
            ]);
        }

        $this->clearLocalState($account);
        $this->em->flush();
    }

    public function needsRenewal(Account $account): bool
    {
        if (true !== $account->isPushEnabled()) {
            return false;
        }

        $expiresAt = $account->getGraphSubscriptionExpiresAt();

        if (null === $expiresAt) {
            return true;
        }

        $threshold = new DateTimeImmutable(sprintf('+%d minutes', self::RENEW_THRESHOLD_MINUTES));

        return $expiresAt <= $threshold;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function clearLocalState(Account $account): void
    {
        $account
            ->setGraphSubscriptionId(null)
            ->setGraphSubscriptionClientState(null)
            ->setGraphSubscriptionExpiresAt(null);
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

        return rtrim($this->publicBaseUrl, '/') . $path;
    }

    /**
     * Graph refuses any notification URL that is not HTTPS, and will never
     * reach localhost. Catching that here turns a confusing remote validation
     * error into a clear local log line.
     */
    private function isPubliclyRoutable(): bool
    {
        $base = trim($this->publicBaseUrl);

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
