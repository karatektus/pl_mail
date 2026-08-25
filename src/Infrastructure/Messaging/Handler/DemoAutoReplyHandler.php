<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Infrastructure\Messaging\Message\DemoAutoReplyMessage;
use App\Repository\Mail\AccountRepository;
use App\Service\Demo\DemoInbox;
use App\Service\Demo\DemoMode;
use App\Service\Demo\DemoScenario;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Answers mail a demo visitor sent, a few seconds after they sent it.
 *
 * The whole point is the round trip: the reply arrives on the conversation
 * they were already looking at, in the pane they left open, without a reload.
 * That is threading, Mercure and the ingest pipeline all demonstrating
 * themselves on mail the visitor wrote — which no amount of pre-seeded inbox
 * can show.
 *
 * Re-checks demo mode rather than trusting the envelope. Queued messages
 * outlive the configuration that queued them, and an instance that has been
 * switched out of demo mode must not have scripted mail land in a real mailbox
 * because a job was still in flight.
 */
#[AsMessageHandler]
final readonly class DemoAutoReplyHandler
{
    public function __construct(
        private AccountRepository $accounts,
        private DemoInbox         $inbox,
        private DemoMode          $demoMode,
        private LoggerInterface   $logger,
    ) {
    }

    public function __invoke(DemoAutoReplyMessage $message): void
    {
        if (false === $this->demoMode->isEnabled()) {
            $this->logger->info('Demo auto-reply skipped: demo mode is off.');

            return;
        }

        $account = $this->accounts->find($message->accountId);

        if (null === $account) {
            // The visitor's user was reaped between the send and the reply.
            // Nothing to deliver to, and nothing wrong.
            return;
        }

        $this->inbox->deliver(
            account: $account,
            scenario: $this->scenario($message),
            inReplyTo: $message->inReplyTo,
            subject: $this->replySubject($message->subject),
        );
    }

    /**
     * The answer itself. Vague on purpose: it has to read as a plausible reply
     * to a message nobody has read, so it acknowledges and defers rather than
     * pretending to have understood.
     */
    private function scenario(DemoAutoReplyMessage $message): DemoScenario
    {
        $name = $message->fromName ?? $this->nameFromAddress($message->fromAddress);

        return new DemoScenario(
            key: 'auto-reply',
            subject: $message->subject,
            fromName: $name,
            fromAddress: $message->fromAddress,
            bodyText: "Got it, thank you — that all makes sense.\n\n"
                . "I will come back to you properly later today once I have had a look at my "
                . "diary. Nothing needed from you in the meantime.\n\n"
                . "— This is an automatic reply from the plMail demo. Mail sent here never "
                . "leaves the server.",
        );
    }

    /**
     * One "Re: " and no more. The threader normalises the subject before
     * matching, so stacking prefixes would not break threading — it would just
     * look like software written by nobody.
     */
    private function replySubject(string $subject): string
    {
        if (1 === preg_match('/^re:\s/i', $subject)) {
            return $subject;
        }

        return 'Re: '.$subject;
    }

    /**
     * A display name for someone who was addressed by bare address. "anna.weiss"
     * becomes "Anna Weiss", which is what a mail client shows and what makes
     * the reply look like it came from a person.
     */
    private function nameFromAddress(string $address): string
    {
        $localPart = strstr($address, '@', true);

        if (false === $localPart || '' === $localPart) {
            return $address;
        }

        return ucwords(str_replace(['.', '_', '-'], ' ', $localPart));
    }
}
