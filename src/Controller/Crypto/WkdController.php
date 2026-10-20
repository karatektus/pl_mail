<?php

declare(strict_types=1);

namespace App\Controller\Crypto;

use App\Service\Crypto\WkdDirectory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Web Key Directory, as mail programs ask it: Thunderbird, Proton,
 * `gpg --locate-keys`, and plMail's own WkdLookup on another installation.
 *
 * Both of the draft's shapes, since which one a client reaches depends on DNS
 * the operator may or may not have set up:
 *
 *     /.well-known/openpgpkey/<domain>/hu/<hash>   advanced, on openpgpkey.<domain>
 *     /.well-known/openpgpkey/hu/<hash>            direct, on <domain> itself
 *
 * each with its `policy` file beside it, whose existence is what tells a
 * client the domain has a directory at all.
 *
 * Public, and outside every firewall (security.yaml gives the prefix
 * `security: false`, as it does /branding/): the caller is a mail program with
 * no session and no reason to have one, and a request that touched the session
 * would have Symfony mark the answer private — when an answer here is the same
 * for everybody. No CSRF either: nothing here writes anything a caller chose.
 *
 * **Every miss is the same 404.** An unknown hash, an address whose owner
 * switched publishing off, an address with no key, a domain the installation
 * does not serve, a directory switched off altogether: identical status,
 * headers and (empty) body, so the answer says nothing beyond "no key here".
 * What it cannot hide is the one thing it exists to say — that a published
 * address has a key — and the `wkd_serve` limiter is what slows somebody
 * walking a list of names to find out which addresses exist.
 */
final class WkdController extends AbstractController
{
    /**
     * Ten minutes. A renewed key is served at once; this is how long a cache
     * on the way may go on handing out the one before, and the ETag makes
     * every answer after that a cheap 304 when nothing changed.
     */
    private const int KEY_MAX_AGE = 600;

    /** The policy file changes when an operator edits the deployment. */
    private const int POLICY_MAX_AGE = 86400;

    /** What the draft hashes a local part to: 32 characters of Z-Base-32. */
    private const string HASH = '[ybndrfg8ejkmcpqxot1uwisza345h769]{32}';

    public function __construct(
        private readonly WkdDirectory                $directory,
        private readonly RateLimiterFactoryInterface $wkdServeLimiter,
    ) {}

    /** The direct method: the domain is the host the request came to. */
    #[Route('/.well-known/openpgpkey/hu/{hash}', name: 'app_wkd_direct_key', requirements: ['hash' => self::HASH], methods: ['GET'])]
    public function directKey(Request $request, string $hash): Response
    {
        return $this->answer($request, function () use ($request, $hash): ?Response {
            $domain = $this->directory->directDomain($request->getHost());

            return null === $domain ? null : $this->key($request, $this->directory->find($domain, $hash));
        });
    }

    #[Route('/.well-known/openpgpkey/policy', name: 'app_wkd_direct_policy', methods: ['GET'])]
    public function directPolicy(Request $request): Response
    {
        return $this->answer($request, fn (): ?Response => null === $this->directory->directDomain($request->getHost()) ? null : $this->policy());
    }

    /** The advanced method: the domain is in the path. */
    #[Route('/.well-known/openpgpkey/{domain}/hu/{hash}', name: 'app_wkd_advanced_key', requirements: ['domain' => '[^/]+', 'hash' => self::HASH], methods: ['GET'])]
    public function advancedKey(Request $request, string $domain, string $hash): Response
    {
        return $this->answer($request, fn (): ?Response => $this->key($request, $this->directory->find($domain, $hash)));
    }

    #[Route('/.well-known/openpgpkey/{domain}/policy', name: 'app_wkd_advanced_policy', requirements: ['domain' => '[^/]+'], methods: ['GET'])]
    public function advancedPolicy(Request $request, string $domain): Response
    {
        return $this->answer($request, fn (): ?Response => true === $this->directory->serves($domain) ? $this->policy() : null);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The limit first, then the answer, or the one 404 when there is none.
     *
     * @param \Closure(): ?Response $build
     */
    private function answer(Request $request, \Closure $build): Response
    {
        $limit = $this->wkdServeLimiter->create($request->getClientIp() ?? 'unknown')->consume();

        if (false === $limit->isAccepted()) {
            return new Response('', Response::HTTP_TOO_MANY_REQUESTS, [
                'Retry-After'                 => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()),
                'Access-Control-Allow-Origin' => '*',
            ]);
        }

        return $build() ?? $this->public(new Response('', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain']), self::KEY_MAX_AGE);
    }

    private function key(Request $request, ?string $binary): ?Response
    {
        if (null === $binary) {
            return null;
        }

        $response = $this->public(new Response('', Response::HTTP_OK, ['Content-Type' => 'application/octet-stream']), self::KEY_MAX_AGE);
        $response->setEtag(sha1($binary));

        if (true === $response->isNotModified($request)) {
            return $response;
        }

        return $response->setContent($binary);
    }

    /**
     * Empty: the draft's policy flags are all optional, and an empty file is
     * what says "this domain has a directory, with no special rules".
     */
    private function policy(): Response
    {
        return $this->public(new Response('', Response::HTTP_OK, ['Content-Type' => 'text/plain']), self::POLICY_MAX_AGE);
    }

    /**
     * Readable from any page — a web mail client on another origin looks keys
     * up from the browser, and nothing here is anybody's secret — and
     * cacheable by anybody, since the answer is the same for every caller.
     */
    private function public(Response $response, int $maxAge): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->setPublic();
        $response->setMaxAge($maxAge);

        return $response;
    }
}
