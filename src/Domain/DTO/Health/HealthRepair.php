<?php

declare(strict_types=1);

namespace App\Domain\DTO\Health;

/**
 * One button on a health card: what it will do, and where pressing it goes.
 *
 * Carries a promise as well as a label. The brief this was built to is explicit
 * that a repair states what it will do BEFORE doing it, and a label alone
 * cannot — "Reconnect" does not say that the mail already downloaded stays put,
 * which is the exact fear that makes people delete the account and add it back
 * instead. So every repair has a promiseKey, and the card renders it.
 *
 * GET repairs are links, POST repairs are forms with a token. That split is not
 * cosmetic: the OAuth repairs must leave the app entirely and come back, so
 * they cannot be fetch() calls, while everything else changes state here and
 * must therefore be a POST that carries CSRF.
 */
final readonly class HealthRepair
{
    /**
     * @param string                $route      Symfony route name
     * @param array<string, mixed>  $routeParams
     * @param string                $labelKey   the button's text
     * @param string                $promiseKey one sentence: what pressing it does
     * @param array<string, mixed>  $promiseParams placeholders for the promise.
     *                                            Its own array rather than reusing
     *                                            the issue's body params: the
     *                                            reconnect promise names the
     *                                            mailbox you must sign in as,
     *                                            which is a different sentence
     *                                            from the one describing what
     *                                            broke, and rendering it with no
     *                                            parameters printed a literal
     *                                            "%account%" on the page
     * @param string|null           $csrfTokenId non-null for POST repairs; the id
     *                                           the controller validates against
     * @param bool                  $destructive throws work away rather than
     *                                           retrying it — rendered apart from
     *                                           the safe repairs, never as the
     *                                           primary button
     * @param string|null           $pendingKey  what the button SAYS while the
     *                                           press is in flight. See below
     * @param array<string, mixed>  $pendingParams placeholders for it — the
     *                                             reconnect names the provider it
     *                                             is about to send you to
     */
    public function __construct(
        public string  $route,
        public array   $routeParams,
        public string  $labelKey,
        public string  $promiseKey,
        public array   $promiseParams = [],
        public ?string $csrfTokenId = null,
        public bool    $destructive = false,
        public ?string $pendingKey = null,
        public array   $pendingParams = [],
    ) {
    }

    /**
     * The pending label, or the ordinary one when a repair has not named one.
     *
     * Every repair here does name one — this exists so a repair added later
     * cannot render an empty button by forgetting to. Falling back to the label
     * is the right failure: the control still disables and still says something
     * a person can read, it merely says the same thing it said before.
     */
    public function pendingLabelKey(): string
    {
        return $this->pendingKey ?? $this->labelKey;
    }

    /** POST when it carries a token, GET when it does not. */
    public function isPost(): bool
    {
        return null !== $this->csrfTokenId;
    }
}
