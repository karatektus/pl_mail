<?php

declare(strict_types=1);

namespace App\Service\Demo;

use DateInterval;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Whether this instance is a public demo, and for how long a visitor's
 * throwaway account lives.
 *
 * One switch for the whole install, which is the only shape this can honestly
 * take. Demo mode's promise is that nothing reaches the network and no real
 * mailbox can be attached; a per-user or per-account flag would move that
 * promise into every outbound path individually, where it would hold until the
 * first one that forgot to ask. Asking once, here, means the paths that matter
 * — sync, push renewal, calendar, account creation, SMTP — can each refuse
 * outright rather than refuse conditionally.
 *
 * Read through `default:` so an install that has never heard of the variable is
 * a normal install rather than a demo one. That default is the important half:
 * the failure worth designing against is a real deployment accidentally
 * becoming a demo, not a demo accidentally becoming real.
 */
final readonly class DemoMode
{
    /**
     * The password every demo user is given. Public by design — it is printed
     * on the login page — which is exactly why it may never be reachable
     * outside demo mode: DemoProvisioner is the only caller, and it is the
     * only thing that mints users holding it.
     */
    public const string PASSWORD = 'demo';

    /** Local-part prefix that marks a user as provisioned by the demo. */
    public const string USER_PREFIX = 'demo-';

    /** Domain the throwaway users live on. Reserved by RFC 2606. */
    public const string USER_DOMAIN = 'plmail.invalid';

    public function __construct(
        #[Autowire('%app.demo_mode%')]
        private bool $enabled,
        #[Autowire('%app.demo_ttl%')]
        private string $ttl,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * When a user provisioned now should be reaped.
     *
     * A malformed interval falls back to two hours rather than throwing. This
     * is read on the request that provisions a visitor, and a demo that refuses
     * to let anyone in because an environment variable has a typo in it is a
     * worse outcome than one whose users live slightly longer than intended.
     */
    public function expiryFrom(DateTimeImmutable $now): DateTimeImmutable
    {
        try {
            return $now->add(new DateInterval($this->ttl));
        } catch (\Exception) {
            return $now->add(new DateInterval('PT2H'));
        }
    }

    /**
     * The retention period in words, for the privacy notice.
     *
     * Derived from the configured TTL rather than written into the page: a
     * privacy notice that states a retention period the software does not
     * honour is worse than one that says nothing, and these two would drift the
     * first time somebody tuned APP_DEMO_TTL.
     *
     * Rounded to hours because that is the unit the setting is chosen in and
     * the granularity a notice needs. Anything under an hour reads as minutes.
     */
    public function ttlDescription(): string
    {
        $now     = new DateTimeImmutable('@0');
        $seconds = $this->expiryFrom($now)->getTimestamp();

        if ($seconds < 3600) {
            return sprintf('%d min', (int) round($seconds / 60));
        }

        return sprintf('%d h', (int) round($seconds / 3600));
    }

    /**
     * Whether an address belongs to a demo-provisioned user.
     *
     * The reaper deletes on this predicate, so it is deliberately narrow:
     * both the prefix and the reserved domain must match. An administrator who
     * set up a demo instance and then signed in with their own address keeps
     * their account.
     */
    public function ownsAddress(?string $email): bool
    {
        if (null === $email) {
            return false;
        }

        return str_starts_with($email, self::USER_PREFIX)
            && str_ends_with($email, '@'.self::USER_DOMAIN);
    }
}
