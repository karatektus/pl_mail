<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\User\User;
use App\Jmap\Mail\SubmissionEnvelope;
use App\Service\User\UserTimezoneResolver;
use DateTimeImmutable;
use DateTimeZone;

/**
 * When a scheduled send is allowed to leave, given what the compose window
 * submitted and whose clock it was read off.
 *
 * Its own service rather than four lines in the controller, for two reasons.
 *
 * **The timezone.** The window submits a wall clock — "2026-08-13T09:00" — and
 * a wall clock is not an instant until somebody says whose. The browser's own
 * zone is the wrong answer: a user who set Europe/Berlin in settings and is
 * travelling with a laptop still on America/New_York means nine in the morning
 * Berlin time, because that is the clock every other timestamp in this app is
 * drawn against (TwigTimezoneSubscriber). So the zone comes from
 * UserTimezoneResolver — the single answer to "which wall clock is this user
 * reading?" — and the conversion happens here, in PHP, where the tz database
 * knows what to do with the hour that does not exist on a spring-forward
 * Sunday. Doing it in JavaScript would have meant reimplementing that, twice
 * (once per direction), against Intl's offset formatting.
 *
 * **The ceiling.** JMAP already publishes one: SubmissionEnvelope::
 * MAX_HOLD_SECONDS, advertised to every client as `maxDelayedSend`. A web
 * composer with its own limit would be a second, quieter policy — a schedule
 * the browser accepts and EmailSubmission/set refuses, or the reverse. The
 * constant is imported rather than copied so the two surfaces cannot drift.
 */
final readonly class ScheduledSendResolver
{
    /** The published ceiling, and deliberately not a second opinion on it. */
    public const int MAX_SECONDS = SubmissionEnvelope::MAX_HOLD_SECONDS;

    /**
     * Nothing may be scheduled closer than this.
     *
     * Not arbitrary caution: below a minute, "schedule" and "send" are the same
     * action with a worse undo — the hold would elapse before the toast that
     * offers to cancel it has faded. A user who wants it gone now has a Send
     * button two centimetres to the left.
     */
    public const int MIN_SECONDS = 60;

    /** What the compose window's datetime-local field emits. */
    private const string WALL_CLOCK = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/';

    public function __construct(
        private UserTimezoneResolver $timezones,
    ) {
    }

    /**
     * The instant the submitted time names, in UTC.
     *
     * Two accepted spellings, and the difference is who supplies the zone:
     *
     *  • a bare wall clock ("2026-08-13T09:00"), which is what
     *    <input type="datetime-local"> and the preset buttons submit — read in
     *    the user's configured zone;
     *  • anything carrying its own offset ("…T09:00:00+02:00", "…Z") — already
     *    an instant, taken as one. Nothing in the UI sends this today; it is
     *    here so a caller that does have an absolute time is not forced to
     *    strip the offset and hope this class guesses it back.
     *
     * @throws InvalidScheduleException with a translation key, never a
     *         sentence: the reason is shown to the user in their own language.
     */
    public function resolve(string $submitted, ?User $user, ?DateTimeImmutable $now = null): DateTimeImmutable
    {
        $now      = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'));
        $submitted = trim($submitted);

        if ('' === $submitted) {
            throw new InvalidScheduleException('compose.schedule.error.unreadable');
        }

        $sendAt = $this->toInstant($submitted, $user);

        // Gone, and merely too soon, are two different things to be told.
        // They shared the "already passed" wording until a tester typed the
        // next whole minute, was refused for a time that had plainly not
        // passed, and — because the refusal had nowhere to render — was told
        // nothing at all.
        if ($sendAt->getTimestamp() <= $now->getTimestamp()) {
            throw new InvalidScheduleException('compose.schedule.error.past');
        }

        // Strictly less than the floor, not less than zero. See MIN_SECONDS:
        // a hold measured in seconds is a send, and answering "fine" to it
        // would mean the cancel window and the hold expire together.
        if ($sendAt->getTimestamp() - $now->getTimestamp() < self::MIN_SECONDS) {
            throw new InvalidScheduleException('compose.schedule.error.too_soon');
        }

        if ($sendAt->getTimestamp() - $now->getTimestamp() > self::MAX_SECONDS) {
            throw new InvalidScheduleException('compose.schedule.error.too_far');
        }

        return $sendAt;
    }

    /** The ceiling in whole days, for a message that has to say it. */
    public static function maxDays(): int
    {
        return intdiv(self::MAX_SECONDS, 86_400);
    }

    /** The messenger delay this schedule is worth, never negative. */
    public function delayMs(DateTimeImmutable $sendAt, ?DateTimeImmutable $now = null): int
    {
        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'));

        return max(0, ($sendAt->getTimestamp() - $now->getTimestamp()) * 1000);
    }

    private function toInstant(string $submitted, ?User $user): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');

        // A wall clock is read in the user's zone; PHP applies that zone's
        // rules for the date in question, which is the whole reason this is
        // not `strtotime` plus an offset in minutes.
        if (1 === preg_match(self::WALL_CLOCK, $submitted)) {
            try {
                $local = new DateTimeImmutable($submitted, $this->timezones->resolve($user));
            } catch (\Exception) {
                throw new InvalidScheduleException('compose.schedule.error.unreadable');
            }

            return $local->setTimezone($utc);
        }

        try {
            $absolute = new DateTimeImmutable($submitted);
        } catch (\Exception) {
            throw new InvalidScheduleException('compose.schedule.error.unreadable');
        }

        return $absolute->setTimezone($utc);
    }
}
