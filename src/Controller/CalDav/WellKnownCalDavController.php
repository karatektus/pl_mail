<?php

declare(strict_types=1);

namespace App\Controller\CalDav;

use App\Service\Calendar\Dav\DavPaths;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * /.well-known/caldav, which is the address a person can actually be given.
 *
 * RFC 6764 exists because the alternative is telling somebody to type
 * "https://mail.example.com/caldav/" into a field labelled "server". Clients
 * take a bare hostname, try the well-known path, and follow the redirect to
 * wherever the service really lives — so this is what makes "just enter your
 * server address" work in Thunderbird, DAVx5 and iOS.
 *
 * Answers without requiring authentication, and deliberately: a client asks
 * where the service is before it has anything to authenticate with, and a 401
 * here reads to several of them as "there is no CalDAV here" rather than as a
 * challenge. The redirect leaks nothing — the path it names is a constant, and
 * everything behind it is still guarded.
 *
 * 301 rather than 302, because the location will not change and clients cache
 * it; RFC 6764 §5 asks for a permanent redirect for exactly that reason.
 */
final class WellKnownCalDavController extends AbstractController
{
    public function __construct(
        private readonly DavPaths $paths,
    ) {
    }

    #[Route(
        '/.well-known/caldav',
        name: 'app_caldav_well_known',
        methods: ['GET', 'HEAD', 'OPTIONS', 'PROPFIND', 'REPORT'],
    )]
    public function __invoke(): RedirectResponse
    {
        return new RedirectResponse($this->paths->root(), Response::HTTP_MOVED_PERMANENTLY);
    }
}
