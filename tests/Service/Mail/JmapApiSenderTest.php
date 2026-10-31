<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Exception\SendRefusedException;
use App\Infrastructure\JmapClient\JmapCapability;
use App\Service\Mail\JmapApiSender;
use App\Service\Mail\MailSenderRegistry;
use App\Service\Mail\SmtpMailSender;
use App\Tests\Support\Jmap\SyncsAgainstFakeJmapServer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;

/**
 * Sending from a JMAP account goes through the server's own submission, and
 * the server files the Sent copy (#27).
 *
 * The cases are the ones that would put a wrong thing on the wire or leave
 * one on the server: the mail sent as the identity it is from, moved to Sent
 * once the server took it, and — when the server refuses — no draft left
 * behind for the retry to duplicate.
 */
final class JmapApiSenderTest extends KernelTestCase
{
    use SyncsAgainstFakeJmapServer;

    protected function setUp(): void
    {
        $this->bootJmap();
        $this->server->identities['id-2'] = ['id' => 'id-2', 'email' => 'sales@example.test', 'name' => 'Sales'];
    }

    protected function tearDown(): void
    {
        $this->rollBackJmap();

        parent::tearDown();
    }

    public function testASentMessageIsSubmittedAsItsFromIdentityAndFiledInSent(): void
    {
        $this->sender()->deliver($this->email('sales@example.test'), $this->freshAccount());

        self::assertCount(1, $this->server->submissions);
        self::assertSame('id-2', $this->server->submissions[0]['identityId']);

        $email = $this->server->emails[$this->server->submissions[0]['emailId']];

        self::assertSame(['mb-sent'], array_keys($email['mailboxIds']), 'moved from Drafts to Sent once it went');
        self::assertArrayNotHasKey('$draft', $email['keywords']);
        self::assertStringContainsString('Bcc: hidden@example.test', $email['raw'], 'Bcc reaches the server, which reads recipients from the MIME');
    }

    /**
     * Another plMail lists Drafts and Sent only once something has used
     * them. With neither, the send was refused before it was tried.
     */
    public function testAServerWithNeitherDraftsNorSentGetsASentMailbox(): void
    {
        $this->server->removeMailbox('mb-drafts');
        $this->server->removeMailbox('mb-sent');

        $this->sender()->deliver($this->email('me@example.test'), $this->freshAccount());

        $sent = array_values(array_filter($this->server->mailboxes, static fn (array $box): bool => 'sent' === $box['role']));

        self::assertCount(1, $sent, 'a sent mailbox was made, once');
        self::assertCount(1, $this->server->submissions);
        self::assertSame([$sent[0]['id']], array_keys($this->server->emails[$this->server->submissions[0]['emailId']]['mailboxIds']));
    }

    public function testARefusedSendLeavesNoDraftBehind(): void
    {
        $this->server->refuseSubmission = 'forbiddenFrom';

        try {
            $this->sender()->deliver($this->email('me@example.test'), $this->freshAccount());
            self::fail('a refused submission must throw');
        } catch (SendRefusedException) {
        }

        self::assertSame([], $this->server->emails, 'the imported copy was destroyed again');
    }

    public function testTheSentCopyIsFoundByMessageId(): void
    {
        $email = $this->email('me@example.test');
        $email->getHeaders()->addIdHeader('Message-ID', 'sent-once@example.test');

        $this->sender()->deliver($email, $this->freshAccount());

        self::assertTrue($this->sender()->isInSent($this->freshAccount(), 'sent-once@example.test'));
        self::assertFalse($this->sender()->isInSent($this->freshAccount(), 'never-sent@example.test'), 'and only that one');
    }

    /**
     * Stalwart answers a header condition with nothing, for any value. "Not
     * in Sent" would send the mail again, so the newest of Sent are compared
     * by their Message-ID instead.
     */
    public function testOnAServerWhoseHeaderFilterMatchesNothingTheSentCopyIsStillFound(): void
    {
        $this->server->headerFilterMatches = false;

        $email = $this->email('me@example.test');
        $email->getHeaders()->addIdHeader('Message-ID', 'sent-on-stalwart@example.test');

        $this->sender()->deliver($email, $this->freshAccount());

        self::assertTrue($this->sender()->isInSent($this->freshAccount(), 'sent-on-stalwart@example.test'));
        self::assertTrue($this->sender()->isInSent($this->freshAccount(), '<sent-on-stalwart@example.test>'), 'in either spelling');
        self::assertFalse($this->sender()->isInSent($this->freshAccount(), 'never-sent@example.test'));
    }

    /**
     * Without submission the account is SMTP's to send, and the server keeps
     * the Sent copy of what its relay sent.
     */
    public function testAServerWithoutSubmissionIsLeftToSmtp(): void
    {
        $this->server->capabilities = [JmapCapability::MAIL];

        self::assertFalse($this->sender()->supports($this->freshAccount()));
        self::assertInstanceOf(SmtpMailSender::class, static::getContainer()->get(MailSenderRegistry::class)->resolve($this->freshAccount()));
    }

    private function sender(): JmapApiSender
    {
        return static::getContainer()->get(JmapApiSender::class);
    }

    private function email(string $from): Email
    {
        return new Email()
            ->from($from)
            ->to('ada@example.test')
            ->bcc('hidden@example.test')
            ->subject('Hello')
            ->text('Hello over JMAP.');
    }
}
