<?php

declare(strict_types=1);

namespace App\Tests\Service\System\WhatsNew;

use App\Domain\Interface\MailSenderInterface;
use App\Entity\Mail\Account;
use App\Service\Imap\MessageSendService;
use App\Service\Mail\MailSenderRegistry;
use App\Service\System\AppVersion;
use App\Service\System\WhatsNew\FeedbackMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Mime\Email;

/**
 * The feedback mail itself, and the two things about it worth getting wrong:
 * the screenshot, which arrives from the browser as a data: URL anybody could
 * write, and the Sent copy for an SMTP account (#165).
 */
final class FeedbackMailerTest extends TestCase
{
    private const string PNG = "\x89PNG\r\n\x1a\n" . 'rest of the picture';

    public function testAPngOrAJpegIsAcceptedOnItsBytes(): void
    {
        self::assertSame(self::PNG, FeedbackMailer::decodeScreenshot('data:image/png;base64,' . base64_encode(self::PNG)));
        self::assertSame("\xFF\xD8\xFFjpeg", FeedbackMailer::decodeScreenshot('data:image/jpeg;base64,' . base64_encode("\xFF\xD8\xFFjpeg")));
    }

    public function testAnythingElseIsRefused(): void
    {
        self::assertNull(FeedbackMailer::decodeScreenshot('data:image/png;base64,' . base64_encode('<svg onload=alert(1)>')), 'what the URL claims is not what counts');
        self::assertNull(FeedbackMailer::decodeScreenshot('data:image/svg+xml;base64,' . base64_encode(self::PNG)));
        self::assertNull(FeedbackMailer::decodeScreenshot('https://example.test/shot.png'));
        self::assertNull(FeedbackMailer::decodeScreenshot('data:image/png;base64,' . base64_encode(self::PNG . str_repeat('x', FeedbackMailer::SCREENSHOT_MAX_BYTES))), 'too big');
    }

    /** A name typed where the address belongs is passed over, not sent as a From. */
    public function testTheFromIsTheFirstFieldThatIsAnAddress(): void
    {
        $account           = new Account();
        $account->email    = 'E2E Mailbox';
        $account->username = 'mailbox@example.test';

        self::assertSame('mailbox@example.test', FeedbackMailer::fromAddress($account));

        $nothing           = new Account();
        $nothing->email    = 'E2E Mailbox';
        $nothing->username = 'mailbox';

        self::assertNull(FeedbackMailer::fromAddress($nothing));
        self::assertFalse($this->mailer(new SentRecorder(true))->send($nothing, null, 'Hi'), 'no address is a failed send, not an exception');
    }

    public function testTheMailSaysWhoWhatAndWhichBuild(): void
    {
        $email = $this->mailer(new SentRecorder(true))->build($this->account(), 'worse', "Search is slow.\n", self::PNG);

        self::assertSame('plMail v0.3.6 feedback: Worse', $email->getSubject());
        self::assertSame('me@example.test', $email->getFrom()[0]->getAddress());
        self::assertSame('feedback@example.test', $email->getTo()[0]->getAddress());
        self::assertSame("Search is slow.\n\n--\nSince the update, plMail is: Worse\nplMail v0.3.6 (1a2b3c4)\n", $email->getTextBody());
        self::assertSame('screenshot.png', $email->getAttachments()[0]->getFilename());
    }

    public function testWithoutARatingTheSubjectSaysNothingAboutOne(): void
    {
        self::assertSame('plMail v0.3.6 feedback', $this->mailer(new SentRecorder(true))->build($this->account(), null, 'Hi')->getSubject());
    }

    /** SMTP files no Sent copy of its own, so it is appended; a provider API files its own. */
    public function testTheSentCopyIsFiledOnlyWhereTheSenderDoesNot(): void
    {
        $sendService = $this->createMock(MessageSendService::class);
        $sendService->expects(self::once())->method('appendToSentFolder');

        self::assertTrue($this->mailer(new SentRecorder(false), $sendService)->send($this->account(), null, 'Hi'));

        $apiService = $this->createMock(MessageSendService::class);
        $apiService->expects(self::never())->method('appendToSentFolder');

        self::assertTrue($this->mailer(new SentRecorder(true), $apiService)->send($this->account(), null, 'Hi'));
    }

    /** The mail has left by the time the copy fails: that is still "sent", or a second one follows. */
    public function testAFailedSentCopyIsNotAFailedSend(): void
    {
        $sendService = $this->createStub(MessageSendService::class);
        $sendService->method('appendToSentFolder')->willThrowException(new RuntimeException('IMAP is down'));

        self::assertTrue($this->mailer(new SentRecorder(false), $sendService)->send($this->account(), null, 'Hi'));
    }

    public function testARefusedSendIsReported(): void
    {
        self::assertFalse($this->mailer(new SentRecorder(true, refuse: true))->send($this->account(), null, 'Hi'));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function mailer(MailSenderInterface $sender, ?MessageSendService $sendService = null): FeedbackMailer
    {
        return new FeedbackMailer(
            new MailSenderRegistry([$sender]),
            $sendService ?? $this->createStub(MessageSendService::class),
            new AppVersion('v0.3.6', '1a2b3c4d5e6f'),
            new NullLogger(),
            'feedback@example.test',
        );
    }

    private function account(): Account
    {
        $account        = new Account();
        $account->email = 'me@example.test';

        return $account;
    }
}

/** A sender that keeps what it is given. */
final class SentRecorder implements MailSenderInterface
{
    /** @var list<Email> */
    public array $sent = [];

    public function __construct(private readonly bool $filesOwnCopy, private readonly bool $refuse = false)
    {
    }

    public function supports(Account $account): bool
    {
        return true;
    }

    public function send(Email $email, Account $account): bool
    {
        $this->sent[] = $email;

        return false === $this->refuse;
    }

    public function filesSentCopy(): bool
    {
        return $this->filesOwnCopy;
    }
}
