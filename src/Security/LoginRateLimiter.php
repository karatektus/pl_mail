<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RateLimiter\AbstractRequestRateLimiter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * The password form's throttle: per username+IP, per IP, and per username.
 *
 * Symfony's DefaultLoginRateLimiter keys on username+IP and on IP, and the
 * comment in security.yaml said "per username" about it. It is not: an
 * attacker spreading guesses across addresses gets max_attempts per address
 * against the same account, which with a botnet or an IPv6 /64 is no limit at
 * all. The third limiter keys on the username alone, so an account has a
 * ceiling however many addresses are guessing at it.
 *
 * Deliberately looser than the username+IP one. A per-account limit is also a
 * way to lock the owner out by failing on their behalf, so it is set where a
 * real person never reaches it and a guessing campaign does quickly.
 */
final class LoginRateLimiter extends AbstractRequestRateLimiter
{
    public function __construct(
        private readonly RateLimiterFactoryInterface $loginIpLimiter,
        private readonly RateLimiterFactoryInterface $loginUserIpLimiter,
        private readonly RateLimiterFactoryInterface $loginUserLimiter,
        #[Autowire('%kernel.secret%')]
        #[\SensitiveParameter]
        private readonly string $secret,
    ) {
    }

    /**
     * Clears the per-account counts on a successful login, not the per-IP one.
     *
     * The IP limit bounds how many accounts one client may try, so it has to
     * survive any one of them succeeding — the same reasoning Symfony's default
     * applies. The username-only count is cleared too: whoever just signed in
     * knew the password, so what it was guarding against is moot.
     */
    public function reset(Request $request): void
    {
        $this->userIpLimiter($request)->reset();
        $this->userLimiter($request)->reset();
    }

    /**
     * @return list<LimiterInterface>
     */
    protected function getLimiters(Request $request): array
    {
        return [
            $this->loginIpLimiter->create($this->hash((string) $request->getClientIp())),
            $this->userIpLimiter($request),
            $this->userLimiter($request),
        ];
    }

    private function userIpLimiter(Request $request): LimiterInterface
    {
        return $this->loginUserIpLimiter->create($this->hash($this->username($request).'-'.$request->getClientIp()));
    }

    private function userLimiter(Request $request): LimiterInterface
    {
        return $this->loginUserLimiter->create($this->hash($this->username($request)));
    }

    private function username(Request $request): string
    {
        $username = (string) $request->attributes->get(SecurityRequestAttributes::LAST_USERNAME, '');

        return 1 === preg_match('//u', $username) ? mb_strtolower($username, 'UTF-8') : strtolower($username);
    }

    /** Hashed like Symfony's default, so no address or username sits in the cache in the clear. */
    private function hash(string $data): string
    {
        return strtr(substr(base64_encode(hash_hmac('sha256', $data, $this->secret, true)), 0, 8), '/+', '._');
    }
}
