<?php

declare(strict_types=1);

namespace App\Tests\Service\Demo;

use App\Entity\Mail\Account;
use App\Infrastructure\Messaging\Message\DemoAutoReplyMessage;
use App\Service\Demo\DemoMode;
use App\Service\Mail\DemoMailSender;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sending on a demo: nothing leaves, and the answer is queued rather than sent.
 *
 * The claim worth testing is the negative one — that this sender takes every
 * account while demo mode is on — because that is what keeps SmtpMailSender
 * from ever being asked, and so is the whole of "a demo cannot reach a relay".
 */
final class DemoMailSenderTest extends TestCase
{
    /** @var list<Envelope> */
    private array $dispatched = [];

    private function sender(bool $demo = true): DemoMailSender
    {
        // A closure rather than a captured reference: PHPStan cannot see that
        // an anonymous class writing through `&$dispatched` is read anywhere,
        // and the property it wants is this test's own.
        $record = function (Envelope $envelope): void {
            $this->dispatched[] = $envelope;
        };

        $bus = new class($record) implements MessageBusInterface {
            public function __construct(private readonly \Closure $record)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $envelope = new Envelope($message, $stamps);

                ($this->record)($envelope);

                return $envelope;
            }
        };

        return new DemoMailSender(new DemoMode($demo, 'PT2H'), $bus);
    }

    private function email(): Email
    {
        $email = new Email();
        $email->from('you@example.com');
        $email->to(new Address('anna.weiss@example.com', 'Anna Weiß'));
        $email->subject('About Thursday');
        $email->text('Does Thursday still work?');
        $email->getHeaders()->addIdHeader('Message-ID', ['demo-1@plmail.invalid']);

        return $email;
    }

    public function testEveryAccountIsClaimedWhileDemoModeIsOn(): void
    {
        self::assertTrue($this->sender(demo: true)->supports(new Account()));
    }

    public function testNoAccountIsClaimedWhenDemoModeIsOff(): void
    {
        self::assertFalse($this->sender(demo: false)->supports(new Account()));
    }

    /**
     * MessageSendService appends the Sent copy over IMAP for any sender that
     * answers false — against a host that resolves nowhere here.
     */
    public function testTheSentCopyIsClaimedSoNoImapAppendIsAttempted(): void
    {
        self::assertTrue($this->sender()->filesSentCopy());
    }

    public function testSendingSucceedsAndQueuesADelayedReply(): void
    {
        $sender = $this->sender();

        self::assertTrue($sender->send($this->email(), new Account()));
        self::assertCount(1, $this->dispatched);

        $envelope = $this->dispatched[0];
        $message  = $envelope->getMessage();

        self::assertInstanceOf(DemoAutoReplyMessage::class, $message);
        self::assertNotNull($envelope->last(DelayStamp::class));
        self::assertGreaterThan(0, $envelope->last(DelayStamp::class)->getDelay());
    }

    /**
     * The reply comes from whoever the visitor actually addressed. A canned
     * sender would give the game away the moment they wrote to a name they had
     * made up.
     */
    public function testTheReplyIsFromTheAddressedRecipient(): void
    {
        $this->sender()->send($this->email(), new Account());

        $message = $this->dispatched[0]->getMessage();

        self::assertSame('anna.weiss@example.com', $message->fromAddress);
        self::assertSame('Anna Weiß', $message->fromName);
        self::assertSame('About Thursday', $message->subject);
        self::assertNotNull($message->inReplyTo);
    }
}
