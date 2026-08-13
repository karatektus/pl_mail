<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\User\User;
use App\Service\Mail\InvalidScheduleException;
use App\Service\Mail\ScheduledSendResolver;
use App\Service\User\UserTimezoneResolver;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * What "nine in the morning" means, and when the server refuses to be told.
 *
 * A plain unit test with fixed dates, because the two things worth proving here
 * are both invisible in a round trip through the browser: that the wall clock
 * is read in the user's *configured* zone rather than the container's UTC, and
 * that the reading goes through the tz database rather than a fixed offset.
 * The spring-forward pair below is the whole argument — the same 09:00 on two
 * Mondays either side of the change is 08:00 UTC one week and 07:00 the next,
 * and nothing that stores an offset can get both right.
 */
final class ScheduledSendResolverTest extends TestCase
{
    private const string BERLIN = 'Europe/Berlin';

    /**
     * The one that matters most: a wall clock is not an instant until somebody
     * says whose, and the answer is the timezone setting.
     */
    public function testAWallClockIsReadInTheUsersConfiguredZone(): void
    {
        $now = new DateTimeImmutable('2026-08-10T06:00:00', new DateTimeZone('UTC'));

        $berlin = $this->resolver(self::BERLIN)
            ->resolve('2026-08-12T09:00', $this->user(), $now);

        // 09:00 Berlin in August is CEST, two hours ahead.
        self::assertSame('2026-08-12T07:00:00+00:00', $berlin->format('c'));

        // Read as UTC — the container's default, which is what would happen if
        // the setting were ignored — it would have been two hours later.
        self::assertNotSame(
            (new DateTimeImmutable('2026-08-12T09:00', new DateTimeZone('UTC')))->format('c'),
            $berlin->format('c'),
        );
    }

    /**
     * And across a DST change, without either side being special-cased.
     *
     * 2027-03-28 is the spring-forward Sunday in Europe/Berlin. The Monday
     * before is CET (+1), the Monday after is CEST (+2), and 09:00 is 09:00 to
     * the person who typed it on both.
     */
    public function testTheSameMorningSurvivesASpringForward(): void
    {
        $resolver = $this->resolver(self::BERLIN);
        $user     = $this->user();

        $before = $resolver->resolve(
            '2027-03-22T09:00',
            $user,
            new DateTimeImmutable('2027-03-20T00:00:00', new DateTimeZone('UTC')),
        );

        $after = $resolver->resolve(
            '2027-03-29T09:00',
            $user,
            new DateTimeImmutable('2027-03-20T00:00:00', new DateTimeZone('UTC')),
        );

        self::assertSame('2027-03-22T08:00:00+00:00', $before->format('c'), 'CET, +1');
        self::assertSame('2027-03-29T07:00:00+00:00', $after->format('c'), 'CEST, +2');
    }

    /** A time that already carries an offset is an instant, and is left alone. */
    public function testAnAbsoluteTimeIsTakenAsGiven(): void
    {
        $sendAt = $this->resolver(self::BERLIN)->resolve(
            '2026-08-12T09:00:00+05:00',
            $this->user(),
            new DateTimeImmutable('2026-08-10T06:00:00', new DateTimeZone('UTC')),
        );

        self::assertSame('2026-08-12T04:00:00+00:00', $sendAt->format('c'));
    }

    public function testATimeInThePastIsRefused(): void
    {
        $this->expectException(InvalidScheduleException::class);

        $this->resolver(self::BERLIN)->resolve(
            '2026-08-09T09:00',
            $this->user(),
            new DateTimeImmutable('2026-08-10T06:00:00', new DateTimeZone('UTC')),
        );
    }

    /**
     * And so is a time so close that the hold and the cancel window would
     * expire together — see ScheduledSendResolver::MIN_SECONDS.
     *
     * Refused with its OWN reason, which is the point of the test. The floor
     * used to answer "that time has already passed" about a time that plainly
     * had not, and that wording is what made the refusal impossible to act on:
     * a person types the next whole minute — almost always under the floor —
     * and is told something they can see is untrue. The two conditions are two
     * different things to be told.
     */
    public function testATimeSecondsAwayIsRefusedAsTooSoonRatherThanAsPast(): void
    {
        $now = new DateTimeImmutable('2026-08-10T06:00:00', new DateTimeZone('UTC'));

        try {
            // 08:00:30 Berlin is 06:00:30 UTC — thirty seconds out.
            $this->resolver(self::BERLIN)->resolve('2026-08-10T08:00:30', $this->user(), $now);

            self::fail('a schedule inside the floor should have been refused');
        } catch (InvalidScheduleException $refusal) {
            self::assertSame('compose.schedule.error.too_soon', $refusal->translationKey);
        }
    }

    /** A time genuinely behind the clock still says so. */
    public function testATimeAlreadyGoneIsRefusedAsPast(): void
    {
        $now = new DateTimeImmutable('2026-08-10T06:00:00', new DateTimeZone('UTC'));

        try {
            // 07:59 Berlin is 05:59 UTC — a minute behind.
            $this->resolver(self::BERLIN)->resolve('2026-08-10T07:59', $this->user(), $now);

            self::fail('a schedule in the past should have been refused');
        } catch (InvalidScheduleException $refusal) {
            self::assertSame('compose.schedule.error.past', $refusal->translationKey);
        }
    }

    /**
     * The ceiling is JMAP's, not a second opinion on it — the constant is
     * imported from SubmissionEnvelope so the two surfaces cannot disagree
     * about what a client was told in `maxDelayedSend`.
     */
    public function testTheCeilingIsTheOneJmapAdvertises(): void
    {
        self::assertSame(
            \App\Jmap\Mail\SubmissionEnvelope::MAX_HOLD_SECONDS,
            ScheduledSendResolver::MAX_SECONDS,
        );

        $now      = new DateTimeImmutable('2026-08-10T06:00:00', new DateTimeZone('UTC'));
        $resolver = $this->resolver('UTC');

        // A day inside it is fine…
        self::assertInstanceOf(
            DateTimeImmutable::class,
            $resolver->resolve('2026-09-08T06:00', $this->user(), $now),
        );

        // …and a day past it is not.
        try {
            $resolver->resolve('2026-09-10T06:00', $this->user(), $now);

            self::fail('a schedule beyond maxDelayedSend should have been refused');
        } catch (InvalidScheduleException $refusal) {
            self::assertSame('compose.schedule.error.too_far', $refusal->translationKey);
        }
    }

    public function testGarbageIsRefusedRatherThanGuessedAt(): void
    {
        $this->expectException(InvalidScheduleException::class);

        $this->resolver(self::BERLIN)->resolve('soon-ish', $this->user());
    }

    public function testAnEmptySubmissionIsRefused(): void
    {
        $this->expectException(InvalidScheduleException::class);

        $this->resolver(self::BERLIN)->resolve('', $this->user());
    }

    /** The delay handed to the DelayStamp, and never a negative one. */
    public function testTheDelayIsTheDistanceToTheSendTime(): void
    {
        $resolver = $this->resolver('UTC');
        $now      = new DateTimeImmutable('2026-08-10T06:00:00', new DateTimeZone('UTC'));

        self::assertSame(
            3_600_000,
            $resolver->delayMs(new DateTimeImmutable('2026-08-10T07:00:00', new DateTimeZone('UTC')), $now),
        );

        self::assertSame(
            0,
            $resolver->delayMs(new DateTimeImmutable('2026-08-10T05:00:00', new DateTimeZone('UTC')), $now),
            'a hold that elapsed between accepting it and dispatching is an immediate send',
        );
    }

    // ── fixture ───────────────────────────────────────────────────────────

    private function resolver(string $timezone): ScheduledSendResolver
    {
        return new ScheduledSendResolver(new UserTimezoneResolver($timezone));
    }

    /**
     * A user with no preference of their own, so the install default — the
     * constructor argument above — is what resolves. Both halves are exercised
     * by the controller test, which sets the column.
     */
    private function user(): User
    {
        return new User();
    }
}
