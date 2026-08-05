<?php

declare(strict_types=1);

namespace App\Service\Calendar\Booking;

use App\Entity\Calendar\CalendarBooking;
use App\Jmap\Account\CalendarAccountResolver;
use App\Service\Calendar\Ics\IcsExporter;
use App\Service\Mail\MailSenderRegistry;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Telling the booker their appointment exists, with a calendar file they can
 * open.
 *
 * Through MailSenderRegistry, exactly like EmailAlertChannel and InviteResponder
 * — so a confirmation leaves a Gmail account through the Gmail API and an IMAP
 * account through its own SMTP, and so an install that has configured mail once
 * has configured this too. There is no application-level relay and there must
 * not be one: plMail is a mail client, and the only address it can credibly
 * send from is one the user already owns.
 *
 * **From is the owner, To is the booker.** That is the one place this differs
 * from EmailAlertChannel, whose From and To are the same address because a
 * reminder is not correspondence. A confirmation is: it goes to a stranger, and
 * it has to come from the person whose diary they just booked, or it arrives as
 * mail from nobody about a meeting with nobody. It is also why the owner's
 * address is the one thing about them a booking page reveals — the booker
 * already needed a way to reach them.
 *
 * **The .ics is IcsExporter's, not a second exporter's.** Unlike the shared
 * calendar file, which is built from redacted DTOs because it must not be able
 * to carry a title, this file is the meeting itself — the booker is entitled to
 * every field on it, because every field on it is about their own appointment.
 * So it goes through the exporter that already knows how to write times in the
 * shape the event's zone calls for.
 *
 * **A failure here does not fail the booking.** The slot is taken, the event is
 * written and the transaction is committed by the time this is called; an SMTP
 * server refusing a connection is not a reason to unbook somebody. Logged and
 * swallowed, the same shape EmailAlertChannel uses and for the same reason.
 *
 * ── Deliberate absences ───────────────────────────────────────────────────
 *
 * **No METHOD:REQUEST on the attachment.** IcsExporter writes none, and adding
 * one here would turn the file into an invitation — some clients would then try
 * to reply to it, sending an iTIP response to an owner whose calendar never
 * listed the booker as an attendee and would not know what to do with it.
 *
 * **No copy filed in Sent.** InviteResponder and EmailAlertChannel file none
 * either: appending one means a persisted draft and parts on disk, which is a
 * great deal of machinery for a message the owner can already see as a meeting
 * in their calendar.
 *
 * **No mail to the owner.** The booking IS the notification — it appears in
 * their calendar, marked as a booking, at the hour it was taken. A second
 * message saying so is what makes people turn a feature off.
 */
final readonly class BookingConfirmationMailer
{
    /** How the attachment is labelled. One name, because there is one meeting in it. */
    private const string FILE_NAME = 'appointment.ics';

    public function __construct(
        private CalendarAccountResolver $accounts,
        private MailSenderRegistry      $senders,
        private IcsExporter             $exporter,
        private LoggerInterface         $logger,
    ) {
    }

    /**
     * Send it, and answer whether it went.
     *
     * False for a user with no mail account, which is a real state — somebody
     * can delete their last account and keep a calendar and a booking page —
     * rather than an error. The booking still stands; nobody is told about it
     * by mail.
     */
    public function send(CalendarBooking $booking): bool
    {
        $account = $this->accounts->accountFor($booking->usr);
        $from    = $account?->displayAddress;

        if (null === $account || null === $from || '' === $from) {
            $this->logger->info('Booking: no mail account to send the confirmation from', [
                'bookingId' => $booking->id,
            ]);

            return false;
        }

        // The try covers the MESSAGE as well as the send, and that is not
        // caution for its own sake — it is a 500 that was reached in practice.
        // Account::$displayAddress falls back to $username when the account has
        // no address and no primary alias, and a username need not be an
        // address at all: an IMAP account called "E2E Mailbox" produced
        // RfcComplianceException out of `new Address(...)`, before anything had
        // been sent, on a stranger's POST. The booking was already committed by
        // then, so the person who lost was the one who had booked correctly.
        try {
            // In the booker's own clock, which is the only reason
            // CalendarBooking::$bookerTimeZone is kept. A confirmation naming an
            // hour in somebody else's zone is a confirmation people misread.
            $when = $booking->startsAt
                ->setTimezone($this->zoneOf($booking))
                ->format('D, j M Y, H:i T');

            $email = new Email()
                ->from(new Address($from, (string) $account->name))
                ->to(new Address($booking->bookerEmail, $booking->bookerName))
                ->subject(sprintf('Confirmed: %s', (string) $booking->event->title))
                ->text($this->body($when, (string) $account->name))
                ->attach($this->exporter->one($booking->event), self::FILE_NAME, 'text/calendar');

            return $this->senders->resolve($account)->send($email, $account);
        } catch (\Throwable $e) {
            $this->logger->error('Booking: could not send the confirmation', [
                'bookingId' => $booking->id,
                'accountId' => $account->id,
                'error'     => $e->getMessage(),
            ]);

            return false;
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Plain text and no HTML part.
     *
     * The message carries three facts and an attachment; an HTML part would be
     * a second copy of the same three facts to keep in step, and the .ics is
     * what the recipient actually acts on.
     *
     * Not translated, and the reason is the one SharedIcsBuilder gives about
     * its own summary: this is composed on the owner's install in the owner's
     * locale and read by a stranger who never chose one. Sending it in the
     * owner's language would be at least as wrong as sending it in English,
     * and English is the language the owner's own address book is in.
     */
    private function body(string $when, string $organiser): string
    {
        return sprintf(
            "Your appointment is confirmed.\n\nWhen: %s\nWith: %s\n\n"
            . "The attached calendar file adds it to your own calendar.\n"
            . "If you need to change or cancel it, reply to this message.\n",
            $when,
            $organiser,
        );
    }

    /**
     * The zone the booking was made in, or UTC when the stored name has since
     * stopped resolving — a tz database update can retire an identifier, and a
     * confirmation is not worth throwing over.
     */
    private function zoneOf(CalendarBooking $booking): DateTimeZone
    {
        try {
            return new DateTimeZone($booking->bookerTimeZone);
        } catch (\Exception) {
            return new DateTimeZone('UTC');
        }
    }
}
