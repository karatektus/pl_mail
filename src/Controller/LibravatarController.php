<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\User\Libravatar\LibravatarDefault;
use App\Service\User\Libravatar\LibravatarDirectory;
use App\Service\User\Libravatar\LibravatarImage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * This server as a Libravatar server for its own users (#642):
 * `GET /avatar/<hash>?s=<size>&d=<default>`, exactly the URL the spec says a
 * client builds once DNS has pointed it here.
 *
 * Public, because the clients asking are other people's mail programs, forges
 * and archives, none of them signed in here. What makes that acceptable is
 * what it will answer: a picture only for a user who switched publishing on,
 * while the administrator has the route on (LibravatarDirectory). For every
 * other hash — and for any hash with the route off — the answer is the spec's
 * fallback for an unknown address (LibravatarDefault), so the route cannot be
 * used to learn which addresses exist here.
 *
 * `f=y` ("force default") is honoured the same way: the client asked for the
 * fallback whatever the address is.
 *
 * Cached publicly for a day, which is safe because the ETag carries the
 * picture's file name, and a new picture is a new file name
 * (AvatarStorage::store()). The session listener's no-cache override is
 * switched off for the reason ProfileController::avatar() gives.
 */
final class LibravatarController extends AbstractController
{
    public function __construct(
        private readonly LibravatarDirectory $directory,
        private readonly LibravatarImage     $image,
        #[Autowire(service: 'limiter.libravatar')]
        private readonly RateLimiterFactory  $limiter,
    ) {}

    #[Route('/avatar/{hash}', name: 'app_libravatar', requirements: ['hash' => '[0-9a-fA-F]{32}|[0-9a-fA-F]{64}'], methods: ['GET', 'HEAD'])]
    public function avatar(Request $request, string $hash): Response
    {
        if (false === $this->limiter->create((string) $request->getClientIp())->consume()->isAccepted()) {
            return new Response('', Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => '600']);
        }

        $size    = LibravatarImage::size($request->query->get('s', $request->query->get('size')));
        $default = $request->query->get('d', $request->query->get('default'));
        $forced  = true === in_array(strtolower((string) $request->query->get('f', $request->query->get('forcedefault', ''))), ['y', 'yes', '1', 'true'], true);
        $user    = true === $forced ? null : $this->directory->find($hash);
        $png     = null === $user ? null : $this->image->render($user, $size);

        if (null === $user || null === $png) {
            return LibravatarDefault::answer($hash, $size, null === $default ? null : (string) $default);
        }

        $response = new Response($png, Response::HTTP_OK, ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff']);
        $response->setPublic();
        $response->setMaxAge(86_400);
        $response->setEtag(hash('sha256', sprintf('%s|%d', $user->avatar, $size)));
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->isNotModified($request);

        return $response;
    }
}
