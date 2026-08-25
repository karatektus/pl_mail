<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Interface\MailSenderInterface;
use App\Entity\Mail\Account;
use App\Infrastructure\Messaging\Message\DemoAutoReplyMessage;
use App\Service\Demo\DemoMode;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Mime\Email;

/**
 * Sending on a demo instance: nothing leaves, and something comes back.
 *
 * Registered above every real sender and claims an account only while demo
 * mode is on, which is what makes "a demo cannot send mail" a property of the
 * wiring rather than a rule each sender has to remember. SmtpMailSender is
 * never asked, so there is no relay to misconfigure and no address to leak to.
 *
 * filesSentCopy() is true, and that is load-bearing rather than a shrug.
 * MessageSendService appends the Sent copy over IMAP for any sender that
 * answers false — against a demo account, whose imapHost is a documentation
 * domain that resolves nowhere. Claiming the copy is filed skips that append,
 * and the copy really is filed: the caller adds the Sent label itself
 * immediately afterwards, which on an account with no server is the whole of
 * what "in Sent" means here.
 *
 * The reply is queued rather than delivered inline. A send is a user standing
 * at the screen, and the point of the delay is that they are still looking at
 * it when the answer arrives — a reply that appeared in the same request would
 * be indistinguishable from part of the send.
 */
final readonly class DemoMailSender implements MailSenderInterface
{
    /**
     * Long enough that the answer reads as an answer and not as an echo, short
     * enough that a visitor is still on the thread when it lands.
     */
    private const int REPLY_DELAY_MS = 6000;

    public function __construct(
        private DemoMode            $demoMode,
        private MessageBusInterface $bus,
    ) {
    }

    public function supports(Account $account): bool
    {
        return $this->demoMode->isEnabled();
    }

    public function filesSentCopy(): bool
    {
        return true;
    }

    public function send(Email $email, Account $account): bool
    {
        // Keyed on the Message-ID rather than the row id, because that is what
        // the reply has to quote in In-Reply-To for the threader to put it back
        // on the conversation. MessageSendService stamps it onto both the MIME
        // and the row before calling here, so the two agree.
        $messageId = $email->getHeaders()->get('Message-ID')?->getBodyAsString();

        // Who answers is the first person the visitor addressed. Reading it off
        // the MIME rather than re-querying the row keeps this independent of
        // when the handler happens to run, and means the reply is from whoever
        // they actually typed — a canned sender would give the game away the
        // moment they wrote to a name they had made up.
        $recipient = $email->getTo()[0] ?? null;

        if (null === $recipient) {
            // Nothing to answer. A send with no To is refused long before this,
            // so this is the impossible branch rather than a real case; it
            // returns success because the send itself did succeed.
            return true;
        }

        $this->bus->dispatch(
            new DemoAutoReplyMessage(
                accountId: (int) $account->id,
                inReplyTo: $messageId,
                fromAddress: $recipient->getAddress(),
                fromName: '' !== $recipient->getName() ? $recipient->getName() : null,
                subject: (string) $email->getSubject(),
            ),
            [new DelayStamp(self::REPLY_DELAY_MS)],
        );

        return true;
    }
}
