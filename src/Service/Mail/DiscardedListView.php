<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Repository\Label\Label\LabelRepository;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Whether this request is looking at the bin or at Spam, the two lists a
 * conversation can be partly in.
 *
 * Written for the bin first (BinView, #268), and the reasons are all there.
 * Spam is the same question since #807: a conversation with one message in
 * Spam and the rest in the Inbox was treated as spam everywhere, and the
 * reading pane hid Archive, Delete and Snooze from the Inbox side. Now it is
 * spam only where Spam is being looked at, like a partly deleted one.
 *
 * The list is open when
 *
 *  - the page is that list (`app_mail_trash` / `app_mail_spam`), its pages and
 *    the list frame's refreshes included;
 *  - the page is one account's folder of that role, reached by label id
 *    (`app_mail_label`) from the sidebar's account rows;
 *  - the conversation was opened from it, which the row says with `?bin=1` or
 *    `?spam=1` on its link;
 *  - a row or toolbar action names that list as the one it was pressed in
 *    (every status request carries the list's scope, assets/list_view.js),
 *    or names an account's folder of that role by its label id.
 *
 * Anything else is the live side, including a request with no list at all.
 */
final readonly class DiscardedListView
{
    private const array LISTS = [
        // role value => [the list's route, its scope, the row link's flag]
        'trash' => ['app_mail_trash', 'trash', 'bin'],
        'spam'  => ['app_mail_spam', 'spam', 'spam'],
    ];

    private const string LABEL_SCOPE = 'label';

    public function __construct(
        private RequestStack    $requests,
        private LabelRepository $labels,
    ) {
    }

    /** @param LabelRole::Trash|LabelRole::Spam $role */
    public function isOpen(LabelRole $role): bool
    {
        [$route, $listScope, $flag] = self::LISTS[$role->value]
            ?? throw new \InvalidArgumentException(sprintf('No list view for "%s".', $role->value));

        $request = $this->requests->getMainRequest();

        if (null === $request) {
            return false;
        }

        if ($route === $request->attributes->get('_route')) {
            return true;
        }

        if (true === $request->query->getBoolean($flag)) {
            return true;
        }

        if ('app_mail_label' === $request->attributes->get('_route')) {
            return $this->isLabelOf($role, $request->attributes->get('id'));
        }

        [$scope, $value] = self::scopeOf($request);

        return $listScope === $scope
            || (self::LABEL_SCOPE === $scope && $this->isLabelOf($role, $value));
    }

    /**
     * Whether a label id names a folder of this role. Not checked against the
     * person asking: the label view refuses a label that is not theirs before
     * anything is drawn, and a status request acts on a conversation that is
     * checked on its own, so all a foreign id could decide is which side of
     * the asker's own conversation comes back. The label view has already
     * loaded it, so the lookup is the identity map's.
     */
    private function isLabelOf(LabelRole $role, mixed $id): bool
    {
        if (false === is_numeric($id)) {
            return false;
        }

        return $role === $this->labels->find((int) $id)?->role;
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
