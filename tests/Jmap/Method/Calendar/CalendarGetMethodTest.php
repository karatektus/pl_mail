<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Calendar;

use App\Jmap\Method\Calendar\CalendarGetMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;

/**
 * Calendar/get answers with the user's calendars and with nobody else's.
 *
 * A plMail Calendar is user-scoped — there is no per-account binding the way a
 * Mailbox has one — so the only thing standing between one user's calendar list
 * and another's is that every lookup here is scoped to the owner. A lookup by
 * id alone would be indistinguishable in every other respect: it returns a real
 * calendar, with a real name, and nothing in the response says whose.
 *
 * The second claim is about which account serves them. Calendars belong to the
 * user, so publishing them under every connected account would put one calendar
 * under three accountIds; a client keys objects by (accountId, id) and would
 * draw it three times. One account serves them and the rest answer
 * accountNotSupportedByMethod.
 */
final class CalendarGetMethodTest extends CalendarMethodTestCase
{
    private CalendarGetMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        $this->method = self::getContainer()->get(CalendarGetMethod::class);
    }

    public function testItListsTheUsersOwnCalendars(): void
    {
        $this->seedCalendar('Work');
        $this->seedCalendar('Personal');

        self::assertSame(['Work', 'Personal'], $this->names($this->all()));
    }

    /**
     * The failure this exists for: a lookup that forgot the owner returns a
     * calendar that reads exactly like one of the user's own.
     */
    public function testAnotherUsersCalendarIsNotListed(): void
    {
        $this->seedCalendar('Work');
        $this->seedCalendar('Theirs', false, $this->otherUser());

        self::assertSame(['Work'], $this->names($this->all()));
    }

    /**
     * And asking for it by id is notFound rather than forbidden or an error:
     * both other answers confirm the id exists.
     */
    public function testAnotherUsersCalendarAskedForByIdIsNotFound(): void
    {
        $theirs = $this->seedCalendar('Theirs', false, $this->otherUser());

        $result = $this->method->handle([
            'accountId' => $this->accountId(),
            'ids' => [(string) $theirs->id],
        ], $this->context());

        self::assertSame([], $result['list']);
        self::assertSame([(string) $theirs->id], $result['notFound']);
    }

    /**
     * A read-only calendar says so through myRights, which is where every JMAP
     * client already looks — rather than through a second flag that a client
     * would have to know to consult.
     */
    public function testAReadOnlyCalendarWithholdsTheWriteRights(): void
    {
        $this->seedCalendar('Mirror', true);

        $rights = $this->all()['list'][0]['myRights'];

        self::assertTrue($rights['mayReadItems']);
        self::assertFalse($rights['mayAddItems']);
        self::assertFalse($rights['mayUpdateAll']);
        self::assertFalse($rights['mayRemoveItems']);
    }

    /**
     * A second mail account is not a second calendar list. Serving the same
     * calendars from both would publish each one twice, under two accountIds
     * and with the same id — which a client has no way to recognise as one
     * calendar.
     */
    public function testASecondAccountDoesNotAlsoServeTheCalendars(): void
    {
        $this->seedCalendar('Work');

        $second = $this->secondAccount();

        $this->expectException(MethodException::class);

        $this->method->handle([
            'accountId' => (string) $second->id,
            'ids' => null,
        ], new JmapContext($this->user));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    private function all(): array
    {
        return $this->method->handle(['accountId' => $this->accountId()], $this->context());
    }

    /**
     * @param array<string,mixed> $result
     *
     * @return list<string>
     */
    private function names(array $result): array
    {
        $names = [];

        foreach ($result['list'] as $calendar) {
            $names[] = (string) $calendar['name'];
        }

        return $names;
    }
}
