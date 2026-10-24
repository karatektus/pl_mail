<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Entity\Mail\Message;
use App\Service\Imap\MessageSendService;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;

/**
 * Two things about the mail that actually leaves, read off the MIME it is
 * built into: whose name is in From, and what became of a `data:` image.
 *
 * buildEmail() is private and is reached by reflection, deliberately. Both
 * were bugs of the same shape — the app showing one thing and the wire
 * carrying another — so the assertion has to be made on the wire, and going
 * through send() would need a mail server to say the same thing more slowly.
 * Nothing is persisted: the method only reads the two objects it is given.
 */
final class OutgoingSenderNameAndImagesTest extends KernelTestCase
{
    /** A one-pixel PNG. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /**
     * #70. The From selector labelled the option "Mr and Mrs Smith" and the
     * mail went out under the account holder's name.
     */
    public function testMailFromAnAliasWithItsOwnNameGoesOutUnderThatName(): void
    {
        $account = $this->account();
        $message = $this->message($account, 'family@example.test');

        $from = $this->build($message, $account)->getFrom()[0];

        self::assertSame('family@example.test', $from->getAddress());
        self::assertSame('Mr and Mrs Smith', $from->getName());
    }

    public function testMailFromAnAddressWithNoNameOfItsOwnGoesOutUnderTheAccounts(): void
    {
        $account = $this->account();

        self::assertSame('John Smith', $this->build($this->message($account, 'me@example.test'), $account)->getFrom()[0]->getName());
        self::assertSame('John Smith', $this->build($this->message($account, 'yo@shop.test'), $account)->getFrom()[0]->getName());
    }

    /** A signature's embedded logo: attached and referenced, not left as a data URI. */
    public function testADataImageInTheBodyLeavesAsAnInlinePart(): void
    {
        $account           = $this->account();
        $message           = $this->message($account, 'me@example.test');
        $message->bodyHtml = '<p>Hello</p><div data-pl-signature><img src="data:image/png;base64,' . self::PNG . '" alt="Acme"></div>';

        $wire = $this->build($message, $account)->toString();

        self::assertStringContainsString('multipart/related', $wire);
        self::assertStringContainsString('Content-Type: image/png', $wire);
        self::assertStringNotContainsString('data:image/png', quoted_printable_decode($wire));
        self::assertMatchesRegularExpression('~<img src="cid:[^"]+" alt="Acme">~', quoted_printable_decode($wire));
        self::assertStringContainsString('data:image/png', (string) $message->bodyHtml, 'the stored body is not rewritten');
    }

    private function build(Message $message, Account $account): Email
    {
        $service = self::getContainer()->get(MessageSendService::class);

        return new ReflectionMethod($service, 'buildEmail')->invoke($service, $message, $account);
    }

    private function message(Account $account, string $from): Message
    {
        $message              = new Message();
        $message->account     = $account;
        $message->fromAddress = $from;
        $message->subject     = 'Hello';
        $message->bodyHtml    = '<p>Hello</p>';
        $message->toAddresses = [['name' => 'Rike', 'address' => 'rike@example.test']];

        return $message;
    }

    private function account(): Account
    {
        $account        = new Account();
        $account->name  = 'John Smith';
        $account->email = 'me@example.test';

        $account->addAlias(new EmailAlias($account, 'me@example.test', EmailAliasSource::Manual, EmailAliasStatus::Primary));
        $account->addAlias(new EmailAlias($account, 'family@example.test', EmailAliasSource::Manual, EmailAliasStatus::Active, 'Mr and Mrs Smith'));

        return $account;
    }
}
