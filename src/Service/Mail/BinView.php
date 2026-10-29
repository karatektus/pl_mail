<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Repository\Label\LabelRepository;
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
 *  - the page is one account's Trash. The account's folder rows in the
 *    sidebar link every folder by label id (`app_mail_label`), the bin
 *    included, so the label view is the bin when its label has the Trash
 *    role. It was the live side, and a conversation partly in the bin was
 *    listed there and opened without the part it was listed for — a deleted
 *    draft could be seen nowhere;
 *  - the conversation was opened from it, which the row says with `?bin=1` on
 *    its link (the thread route has no list behind it otherwise);
 *  - a row or toolbar action names Trash as the list it was pressed in. Every
 *    status request carries the list's scope (assets/list_view.js), and the
 *    row it streams back is drawn for that list. One account's Trash names
 *    itself as the label it is (`label` and its id), so that scope counts too
 *    when the label is the bin.
 *
 * Anything else is the live side, including a request with no list at all — a
 * search hit, a link, a worker rendering a row. A thread that is wholly in the
 * bin looks the same from both sides, so nothing reached that way is hidden.
 */
final readonly class BinView
{
    private const string TRASH_SCOPE = 'trash';

    private const string LABEL_SCOPE = 'label';

    public function __construct(
        private RequestStack    $requests,
        private LabelRepository $labels,
    ) {
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

        if ('app_mail_label' === $request->attributes->get('_route')) {
            return $this->isTrashLabel($request->attributes->get('id'));
        }

        [$scope, $value] = self::scopeOf($request);

        return self::TRASH_SCOPE === $scope
            || (self::LABEL_SCOPE === $scope && $this->isTrashLabel($value));
    }

    /**
     * Whether a label id names a bin. Not checked against the person asking:
     * the label view refuses a label that is not theirs before anything is
     * drawn, and a status request acts on a conversation that is checked on
     * its own, so all a foreign id could decide is which side of the asker's
     * own conversation comes back. The label view has already loaded it, so
     * the lookup is the identity map's.
     */
    private function isTrashLabel(mixed $id): bool
    {
        if (false === is_numeric($id)) {
            return false;
        }

        return LabelRole::Trash === $this->labels->find((int) $id)?->role;
    }

    /**
     * The list a JSON status request was pressed in, as its scope and value.
     * Anything that is not a JSON object — a form post, an empty body — names
     * no list.
     *
     * @return array{?string, mixed}
     */
    private static function scopeOf(Request $request): array
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return [null, null];
        }

        try {
            $body = $request->getPayload()->all();
        } catch (JsonException) {
            return [null, null];
        }

        $scope = $body['scope'] ?? null;

        return [is_string($scope) ? $scope : null, $body['value'] ?? null];
    }
}
