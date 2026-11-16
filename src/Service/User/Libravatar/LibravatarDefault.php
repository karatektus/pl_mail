<?php

declare(strict_types=1);

namespace App\Service\User\Libravatar;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the Libravatar route answers for a hash it has no picture for (#642),
 * following the `d=` the client sent, as the spec lays it out:
 *
 *   d=404            a 404, for a client that draws its own fallback
 *   d=<http(s) URL>  a redirect to that image
 *   d=mp, identicon  a redirect to libravatar.org, which draws them — and so
 *   … or no d        on to Gravatar for an address it does not know either
 *
 * **Every miss looks the same.** An address nobody here has, a user who has
 * not opted in, and the whole feature switched off all come through this one
 * answer, so the route cannot be asked which addresses exist on this server.
 *
 * A redirect to the URL a client names is what the spec asks for, and every
 * Libravatar and Gravatar server does it. Only http(s) URLs are followed:
 * `javascript:` or `data:` behind a 302 is nothing a picture needs.
 */
final class LibravatarDefault
{
    private const string FALLBACK = 'https://seccdn.libravatar.org/avatar/%s?%s';

    /** The built-in defaults libravatar.org draws; `mm` is the old name for `mp`. */
    private const array BUILT_IN = ['mp', 'mm', 'identicon', 'monsterid', 'wavatar', 'retro', 'robohash', 'pagan', 'blank'];

    public static function answer(string $hash, int $size, ?string $default): Response
    {
        $default = null === $default ? null : trim($default);

        if ('404' === $default) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'public, max-age=300']);
        }

        if (null !== $default && 1 === preg_match('#^https?://#i', $default) && false !== filter_var($default, FILTER_VALIDATE_URL)) {
            return self::redirect($default);
        }

        $query = ['s' => $size];

        if (null !== $default && true === in_array(strtolower($default), self::BUILT_IN, true)) {
            $query['d'] = strtolower($default);
        }

        return self::redirect(sprintf(self::FALLBACK, strtolower($hash), http_build_query($query)));
    }

    private static function redirect(string $url): RedirectResponse
    {
        $response = new RedirectResponse($url, Response::HTTP_FOUND);
        // Short and public: a miss now may be a picture once somebody opts in.
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }
}
