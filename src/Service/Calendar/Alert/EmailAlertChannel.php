<?php

declare(strict_types=1);

namespace App\Service\Calendar\Alert;

use App\Domain\DTO\Calendar\DueAlert;
use App\Domain\Enum\Calendar\AlertAction;
use App\Domain\Interface\AlertChannelInterface;
use App\Jmap\Account\CalendarAccountResolver;
use App\Service\Mail\MailSenderRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * An email alert: a reminder the user sends to themselves.
 *
 * Through MailSenderRegistry, exactly like an iTIP reply, so an alert leaves a
 * Gmail account through the Gmail API and an IMAP account through its own SMTP
 * — and so an install that has configured mail once has configured this too.
 * There is no second transport and no application-level "system mailer": plMail
 * is a mail client, not a service with an outbound relay of its own, and the
 * only address it can credibly send from is one the user already owns.
 *
 * **Which account, and why that rule is not decided here.** The sending account
 * is the one CalendarAccountResolver already calls the calendar's — the user's
 * first — because calendars in plMail are the user's rather than an account's
 * and something had to pick. Asking there rather than repeating "the first one"
 * is what stops this and JMAP disagreeing about which mailbox a calendar
 * belongs to.
 *
 * **From and To are the same address on purpose.** This is not correspondence;
 * it is a reminder, and it has to arrive in the mailbox the user actually reads,
 * which is the account's own. Sending it from anywhere else would land it in
 * spam on any host that checks alignment.
 *
 * No Sent copy is filed, for the same reason InviteResponder files none:
 * appending one means going through MessageSendService with a persisted draft
 * and parts on disk, which is a great deal of machinery for a message the user
 * is about to receive anyway.
 *
 * A user with no mail account answers false rather than throwing. That is a real
 * state — someone can delete their last account and keep a calendar — and it is
 * the deliverer's job to say so, not this one's.
 */
final readonly class EmailAlertChannel implements AlertChannelInterface
{
    public function __construct(
        private CalendarAccountResolver $accounts,
        private MailSenderRegistry      $senders,
        private AlertMessageBuilder     $wording,
        private LoggerInterface         $logger,
    ) {
    }

    public function supports(AlertAction $action): bool
    {
        return AlertAction::Email === $action;
    }

    public function deliver(DueAlert $due): bool
    {
        $account = $this->accounts->accountFor($due->user);
        $address = $account?->displayAddress;

        if (null === $account || null === $address || '' === $address) {
            return false;
        }

        $email = new Email()
            ->from(new Address($address, (string) $account->name))
            ->to(new Address($address, (string) $account->name))
            ->subject($this->wording->subject($due))
            ->text(sprintf("%s\n%s\n", $this->wording->title($due), $this->wording->body($due)));

        try {
            return $this->senders->resolve($account)->send($email, $account);
        } catch (\Throwable $e) {
            // Caught rather than propagated: one account whose SMTP is refusing
            // connections must not stop the sweep delivering everybody else's
            // alerts. The claim is already written, so this alert is simply lost
            // — see CalendarAlertDeliveryRepository::claim() for why that is
            // preferred to a retry.
            $this->logger->error('CalendarAlert: could not send the reminder mail', [
                'userId'    => $due->userId,
                'eventId'   => $due->eventId,
                'accountId' => $account->id,
                'error'     => $e->getMessage(),
            ]);

            return false;
        }
    }
}
