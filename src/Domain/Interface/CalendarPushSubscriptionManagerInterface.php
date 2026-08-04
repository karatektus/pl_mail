<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use App\Domain\Enum\PushHealth;
use App\Entity\Calendar\Calendar;
use DateTimeImmutable;

/**
 * Provider-agnostic push registration for one mirrored calendar.
 *
 * A second interface beside PushSubscriptionManagerInterface rather than a
 * widening of it, and the argument is worth writing down because "one push
 * interface" is the obvious simplification and it is the wrong one.
 *
 * **The subject is different, not merely narrower.** Every method over there
 * takes an Account and reads columns that live on Account. Push here is per
 * Calendar: one Microsoft mail account can mirror six calendars, each of which
 * needs its own Graph subscription, its own secret and its own expiry, because
 * Graph subscribes to `me/calendars/{id}/events` and not to a mailbox. Widening
 * the existing interface to `Account|Calendar` would hand GmailPushSubscription-
 * Manager and GraphSubscriptionManager a type they cannot serve, and every one
 * of their methods would open with an instanceof — a compile-time contract
 * turned into a runtime cascade, in the three classes least able to afford one.
 * A generic `subscribe(object $subject)` gives up the type entirely.
 *
 * **The codebase has already made this call once, in writing.** services.yaml
 * says a calendar sync driver is not an integration driver and must not be
 * tagged as one, because the two registries answer different questions. This is
 * the same shape: two tagged iterators, two registries, one vocabulary. What is
 * genuinely shared — PushHealth, the "false means stay on polling" contract, the
 * requirement for a public HTTPS callback — is shared, and nothing is duplicated
 * except a method signature.
 *
 * **What is deliberately not here.** There is no `messageKey()`: the mail
 * contract has one because the accounts settings list renders per-provider copy
 * for a control the user operates, and calendar push has no control — it is on
 * whenever the deployment can receive a callback, and off otherwise. Adding a
 * key for strings nobody renders would be an interface method kept alive by an
 * interface.
 *
 * Every method is best-effort by contract, exactly as the mail one is. Push is
 * an optimisation over the fifteen-minute sweep, never a replacement for it: a
 * self-hosted install may have no publicly reachable HTTPS address at all, so
 * `false` means "this calendar stays on polling" and never "this failed".
 */
interface CalendarPushSubscriptionManagerInterface
{
    public function supports(Calendar $calendar): bool;

    /**
     * Whether the deployment can receive a callback at all — a public HTTPS
     * base URL, in both implementations. Checked before subscribing so a
     * missing address produces one clear local log line rather than a remote
     * rejection per calendar.
     */
    public function isConfigured(): bool;

    /**
     * Register push for this calendar, replacing any registration it holds.
     *
     * Returns false when push could not be established — the caller leaves the
     * calendar on the sweep.
     */
    public function subscribe(Calendar $calendar): bool;

    /**
     * Extend the registration, or make one if there is none.
     *
     * Both providers cap a calendar registration well under a week, so this is
     * the method the scheduled sweep calls; subscribe() is what it falls back
     * to when there is nothing to extend.
     */
    public function renew(Calendar $calendar): bool;

    /**
     * Tear down remotely and locally. Remote errors are swallowed: a channel
     * that can no longer be stopped lapses on its own, and blocking a calendar
     * being unsubscribed on it would be worse.
     */
    public function unsubscribe(Calendar $calendar): void;

    public function needsRenewal(Calendar $calendar): bool;

    public function expiresAt(Calendar $calendar): ?DateTimeImmutable;

    public function health(Calendar $calendar): PushHealth;
}
