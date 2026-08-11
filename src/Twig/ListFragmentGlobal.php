<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Whether this request wants the message list on its own.
 *
 * The list refresh used to ask for the current URL and get the whole page back
 * — topbar, sidebar, calendar pane, reading pane, 80 KB of it — then parse the
 * lot client-side, lift one frame out and throw the rest away. Answering with
 * just the frame is the same content at a fraction of the cost.
 *
 * Keyed on a header of our own rather than on `Turbo-Frame`, and that
 * distinction matters. Turbo sets `Turbo-Frame` on ordinary frame navigations
 * too — the sidebar's mail links navigate this very frame — and Turbo reads the
 * response's <title> to update history when it advances. Stripping the document
 * down for those would quietly stop the tab title changing as you move between
 * folders. So only the poll, which sends this header and wants no title, gets
 * the short answer.
 *
 * @see assets/controllers/mail/mail_pane_controller.js
 */
final readonly class ListFragmentGlobal
{
    /** Sent by mail_pane_controller when it refreshes the list in place. */
    public const string HEADER = 'X-List-Fragment';

    public function __construct(private RequestStack $requests) {}

    public function isWanted(): bool
    {
        $request = $this->requests->getCurrentRequest();

        if (null === $request) {
            return false;
        }

        return $request->headers->has(self::HEADER);
    }
}
