<?php

declare(strict_types=1);

namespace App\Service\Mail;

use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Which side of a half-deleted conversation this request is looking at.
 *
 * A message moved to Trash stays in its conversation, and since #268 the
 * conversation stays in the Inbox and its labels until every message of it is
 * deleted. So one thread is listed twice, in the Inbox for its live mail and in
 * Trash for the deleted part, and each of the two had been drawn as the whole
 * thread: opened from the Inbox it showed the deleted drafts among the mail,
 * unmarked, and its row offered Restore and Delete forever because the thread
 * wore Trash. The Android app answers the same way since 0.4.2: what is shown
 * is the side the user came from, all deleted or none of it.
 *
 * The bin is being looked at when
 *
 *  - the page is the Trash list (`app_mail_trash`), including its pages and
 *    the list frame's refreshes, which are the same route;
 *  - the conversation was opened from it, which the row says with `?bin=1` on
 *    its link (the thread route has no list behind it otherwise);
 *  - a row or toolbar action names Trash as the list it was pressed in. Every
 *    status request carries the list's scope (assets/list_view.js), and the
 *    row it streams back is drawn for that list.
 *
 * Anything else is the live side, including a request with no list at all — a
 * search hit, a link, a worker rendering a row. A thread that is wholly in the
 * bin looks the same from both sides, so nothing reached that way is hidden.
 */
final readonly class BinView
{
    private const string TRASH_SCOPE = 'trash';

    public function __construct(private RequestStack $requests)
    {
    }

    public function isOpen(): bool
    {
        $request = $this->requests->getMainRequest();

        if (null === $request) {
            return false;
        }

        if ('app_mail_trash' === $request->attributes->get('_route')) {
            return true;
        }

        if (true === $request->query->getBoolean('bin')) {
            return true;
        }

        return self::TRASH_SCOPE === self::scopeOf($request);
    }

    /**
     * The list a JSON status request was pressed in. Anything that is not a
     * JSON object — a form post, an empty body — names no list.
     */
    private static function scopeOf(Request $request): ?string
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return null;
        }

        try {
            $scope = $request->getPayload()->all()['scope'] ?? null;
        } catch (JsonException) {
            return null;
        }

        return is_string($scope) ? $scope : null;
    }
}
