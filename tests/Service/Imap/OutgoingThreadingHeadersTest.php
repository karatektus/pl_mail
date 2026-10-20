<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Service\Imap\MessageSendService;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\IdentificationHeader;

/**
 * #206. A reply left plMail without In-Reply-To or References, so the
 * recipient's client showed it as a new conversation.
 *
 * The draft always had the ids; buildEmail() never wrote them. The assertion
 * is made on the MIME the mail is built into, because the bug lived between
 * the stored row and the wire. buildEmail() is private and reached by
 * reflection, as in OutgoingSenderNameAndImagesTest, and nothing is persisted.
 */
final class OutgoingThreadingHeadersTest extends KernelTestCase
{
    public function testAReplyCarriesItsParentAndTheChainAboveIt(): void
    {
        $message             = $this->message();
        $message->inReplyTo  = ['parent@example.test'];
        $message->references = ['root@example.test', 'parent@example.test'];

        $email = $this->build($message);

        self::assertSame(['parent@example.test'], $this->ids($email, 'In-Reply-To'));
        self::assertSame(['root@example.test', 'parent@example.test'], $this->ids($email, 'References'));
    }

    public function testReferencesEndsWithTheParentEvenWhenTheDraftLeftItOut(): void
    {
        $message             = $this->message();
        $message->inReplyTo  = ['parent@example.test'];
        $message->references = ['root@example.test'];

        $references = $this->ids($this->build($message), 'References');

        self::assertSame(['root@example.test', 'parent@example.test'], $references);
    }

    /**
     * A list that names the parent somewhere other than last — a JMAP client
     * is free to send its own order — still ends with it, which is the one
     * position a threading client reads it from.
     */
    public function testReferencesEndsWithTheParentEvenWhenTheDraftListedItEarlier(): void
    {
        $message             = $this->message();
        $message->inReplyTo  = ['parent@example.test'];
        $message->references = ['parent@example.test', 'root@example.test'];

        $references = $this->ids($this->build($message), 'References');

        self::assertSame(['root@example.test', 'parent@example.test'], $references);
    }

    public function testAReplyWithNoReferencesListStillGetsTheParentAsOne(): void
    {
        $message            = $this->message();
        $message->inReplyTo = ['<parent@example.test>'];

        $email = $this->build($message);

        self::assertSame(['parent@example.test'], $this->ids($email, 'In-Reply-To'));
        self::assertSame(['parent@example.test'], $this->ids($email, 'References'));
    }

    public function testAMailThatIsNotAReplyGetsNeitherHeader(): void
    {
        $headers = $this->build($this->message())->getHeaders();

        self::assertFalse($headers->has('In-Reply-To'));
        self::assertFalse($headers->has('References'));
    }

    /** @return list<string> */
    private function ids(Email $email, string $name): array
    {
        $header = $email->getHeaders()->get($name);

        self::assertInstanceOf(IdentificationHeader::class, $header, $name . ' should be on the mail');

        return $header->getIds();
    }

    private function build(Message $message): Email
    {
        $service = self::getContainer()->get(MessageSendService::class);

        return new ReflectionMethod($service, 'buildEmail')->invoke($service, $message, $message->account);
    }

    private function message(): Message
    {
        $account        = new Account();
        $account->name  = 'John Smith';
        $account->email = 'me@example.test';

        $message              = new Message();
        $message->account     = $account;
        $message->fromAddress = 'me@example.test';
        $message->subject     = 'Re: Hello';
        $message->bodyHtml    = '<p>Hello</p>';
        $message->toAddresses = [['name' => 'Rike', 'address' => 'rike@example.test']];

        return $message;
    }
}
