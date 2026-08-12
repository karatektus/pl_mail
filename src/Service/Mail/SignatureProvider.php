<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Helper\AddressHelper;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;

/**
 * Which signature an address signs with, and what that looks like in a body.
 *
 * One class because there are four callers who must reach the same answer —
 * the compose window seeding a new draft, ReplyDraftBuilder seeding a reply,
 * the From switch swapping one signature for another in the browser, and
 * "Identity/get" publishing it to a phone — and a signature re-derived per
 * caller is a signature that eventually differs per caller. A user who sees
 * one sign-off in the composer and another in the mail that arrives has been
 * lied to by whichever of the two was wrong.
 *
 * TWO LEVELS, AND THE DIFFERENCE BETWEEN "NOTHING" AND "NOT SAID"
 * ───────────────────────────────────────────────────────────────
 * Account::SETTING_SIGNATURE is the account's, and
 * Account::signatureAliasSetting($id) overrides it for one address. The
 * override is read by the key's PRESENCE, not by its value: an absent key
 * means the alias never had an opinion and inherits, while a key holding the
 * empty string means the alias deliberately signs with nothing. Collapsing
 * those two into "empty means inherit" would make it impossible to have one
 * unsigned address on a signed account, which is exactly what a personal
 * alias on a work mailbox wants.
 *
 * THE WRAPPER
 * ───────────
 * A signature in a body is wrapped in `<div class="pl-signature"
 * data-pl-signature>`. That marker is the whole mechanism behind replacing a
 * signature rather than accumulating them: the toolbar button and the From
 * switch both look for it and swap its contents, so switching From five times
 * leaves one signature and not five. It rides along into the sent mail, which
 * is harmless — it is a div with a class, and it is also what lets a reply to
 * our own mail be recognised later if anything ever wants to.
 *
 * The wrapper is added HERE and never comes out of the sanitiser, because the
 * sanitiser's allow-list drops class and data- attributes. Stored signatures
 * are therefore always the inner HTML alone.
 */
final readonly class SignatureProvider
{
    public function __construct(
        private MailBodySanitizer $sanitizer,
    ) {
    }

    /**
     * The signature HTML in force for one sending address, or null when that
     * address signs with nothing.
     *
     * The address is matched against the account's aliases the same way the
     * From selector's token carries it — an address string, not an alias id —
     * because that is what ComposeType puts in the choice value and what comes
     * back on the POST.
     */
    public function htmlFor(Account $account, ?string $address): ?string
    {
        $alias = $this->aliasFor($account, $address);

        if (null !== $alias && null !== $alias->id) {
            $override = $account->getSetting(Account::signatureAliasSetting((int) $alias->id));

            // Present-but-empty is an answer: this address signs with nothing.
            // Only an absent key falls through to the account signature.
            if (null !== $override) {
                return $this->orNull($override);
            }
        }

        return $this->orNull($account->getSetting(Account::SETTING_SIGNATURE));
    }

    /**
     * The signature for one address, wrapped and ready to sit in a body, or
     * the empty string when there is nothing to sign with.
     */
    public function blockFor(Account $account, ?string $address): string
    {
        return $this->block($this->htmlFor($account, $address));
    }

    /** Wrap a signature's HTML in the marker the composer swaps on. */
    public function block(?string $html): string
    {
        if (null === $html || '' === trim($html)) {
            return '';
        }

        return '<div class="pl-signature" data-pl-signature>' . $html . '</div>';
    }

    /**
     * Clean a signature on its way into storage.
     *
     * Called by every writer — the settings panel and "Identity/set" — because
     * a signature is HTML that gets injected into every outgoing message, and
     * stored HTML is never trusted on the way in or on the way out.
     */
    public function sanitize(?string $html): string
    {
        return $this->sanitizer->sanitizeFragment($html);
    }

    /**
     * The plain-text rendering JMAP's `textSignature` is expected to carry.
     */
    public function toText(?string $html): string
    {
        if (null === $html || '' === trim($html)) {
            return '';
        }

        $text = html_entity_decode(
            strip_tags((string) preg_replace('/<(br|\/p|\/div|\/li)[^>]*>/i', "\n", $html)),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );

        return trim((string) preg_replace('/[ \t]*\R\s*/u', "\n", $text));
    }

    /**
     * Every sending address of every account, keyed by the same
     * "accountId|address" token the From selector carries, mapped to the
     * ready-to-insert block.
     *
     * This is what the compose window hands the browser, so that switching
     * From swaps the signature without a round trip — the alternative is a
     * fetch on every change of a dropdown, which would put a network failure
     * in the middle of a keystroke-level interaction.
     *
     * @param iterable<Account> $accounts
     *
     * @return array<string, string>
     */
    public function tokenMap(iterable $accounts): array
    {
        $map = [];

        foreach ($accounts as $account) {
            if (false === (bool) $account->isActive) {
                continue;
            }

            $addresses = [];

            foreach ($account->sendableAliases as $alias) {
                $addresses[] = $alias->address;
            }

            if ([] === $addresses) {
                $addresses[] = $account->displayAddress ?? $account->email ?? '';
            }

            // The token a freshly opened window is pre-set with is built from
            // the account's display address (SenderResolver::token), which is
            // not always one of the alias rows — include it so the map answers
            // for the option the window actually starts on.
            $addresses[] = $account->displayAddress ?? $account->email ?? '';

            foreach ($addresses as $address) {
                if ('' === $address) {
                    continue;
                }

                $map[$account->id . '|' . $address] = $this->blockFor($account, $address);
            }
        }

        return $map;
    }

    private function aliasFor(Account $account, ?string $address): ?EmailAlias
    {
        $wanted = AddressHelper::email($address);

        if ('' === $wanted) {
            return null;
        }

        foreach ($account->aliases as $alias) {
            if (AddressHelper::email($alias->address) === $wanted) {
                return $alias;
            }
        }

        return null;
    }

    private function orNull(mixed $stored): ?string
    {
        if (false === is_string($stored) || '' === trim($stored)) {
            return null;
        }

        return $stored;
    }
}
