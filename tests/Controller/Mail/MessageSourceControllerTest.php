<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Helper\RawMessageStorage;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Service\Mail\RawMessageResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Show original" has to show the original, and its one button has to work.
 *
 * Two faults met on this page, and neither is visible from inside the template:
 *
 *   - The view asked MessageSourceBuilder unconditionally, so what it printed
 *     was a flattening of the parsed header map plus the decoded text body: no
 *     MIME structure, no boundaries, no HTML part — under a heading that said
 *     "original". Anyone comparing it against what a client rendered, or
 *     against a DKIM signature, was comparing against something the sender
 *     never wrote. Message::$rawPath had been carrying the real bytes for some
 *     time by then; this controller was simply never told.
 *
 *   - The copy button's listener lived in a bare <script>. The app's policy is
 *     script-src 'self' 'nonce-…' with no 'unsafe-inline', and it is ENFORCED
 *     only once debug is off — under debug the same policy rides along as
 *     Report-Only. So the button worked in every development run and did
 *     nothing whatsoever in production: no clipboard, no error, no visual
 *     change, and nothing in a log to report.
 *
 * Written as requests rather than against the controller, because both faults
 * live in a seam. The first is a choice between two collaborators that a unit
 * test of either one passes happily; the second is a single attribute whose
 * absence costs nothing until a response listener and a browser are involved.
 */
final class MessageSourceControllerTest extends WebTestCase
{
    /**
     * The boundary token of the fixture below, asserted on as a delimiter line
     * ("--" + token) rather than bare: the delimiter is the thing a flattened
     * header map can never produce, so it is what separates the two answers.
     */
    private const string BOUNDARY = 'plmail-raw-fixture-boundary';

    /** Lives only in the HTML part, which the reconstruction drops entirely. */
    private const string HTML_MARKER = 'the HTML part only the original carries';

    /** Rendered from message.original.reconstructed, in the default locale. */
    private const string NOTICE_TEXT = 'so this is a reconstruction from the stored headers and the text body';

    private EntityManagerInterface $em;
    private Connection $connection;
    private RawMessageResolver $rawResolver;
    private RawMessageStorage $rawStorage;
    private User $user;
    private Account $account;

    /** @var list<string> .eml files written to disk, which no rollback removes */
    private array $storedRawPaths = [];

    protected function tearDown(): void
    {
        if (isset($this->rawStorage)) {
            foreach ($this->storedRawPaths as $path) {
                $this->rawStorage->delete($path);
            }
        }

        if (isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The bug, stated as an assertion: with the bytes on disk, the page is the
     * bytes. A `multipart/alternative` boundary and a `text/html` part are
     * exactly what the reconstruction cannot invent, so either fragment being
     * on the page proves the raw file won.
     */
    public function testTheStoredBytesAreServedRatherThanAReconstruction(): void
    {
        $client  = $this->signIn();
        $message = $this->seedMessage();

        $this->storeRaw($message, self::rawMessage());

        $client->request('GET', '/mail/message/' . $message->id . '/original');

        self::assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Content-Type: multipart/alternative', $body);
        self::assertStringContainsString('--' . self::BOUNDARY, $body);
        self::assertStringContainsString(self::HTML_MARKER, $body);

        // And the page does not claim to be a reconstruction while showing
        // the original, which would be the same lie told the other way round.
        self::assertStringNotContainsString('class="notice"', $body);
    }

    /**
     * The honest half. A message whose bytes were never kept — plain IMAP from
     * before raw storage existed — still renders, and says what it is showing.
     * Falling back silently is the failure this notice exists to prevent.
     */
    public function testAMessageWithoutStoredBytesSaysItIsAReconstruction(): void
    {
        $client  = $this->signIn();
        $message = $this->seedMessage();

        self::assertNull($message->rawPath, 'the fixture must have no bytes to find');

        $client->request('GET', '/mail/message/' . $message->id . '/original');

        self::assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('class="notice"', $body);
        self::assertStringContainsString(self::NOTICE_TEXT, $body);
    }

    /**
     * The other fault, pinned where it actually failed: the attribute, and a
     * value in it. A bare <script> fails the first assertion; a template that
     * grew a literal `nonce=""` — or called a nonce helper that is not wired
     * to the one the response header carries — fails the second.
     */
    public function testTheCopyScriptCarriesANonce(): void
    {
        $client  = $this->signIn();
        $message = $this->seedMessage();

        $client->request('GET', '/mail/message/' . $message->id . '/original');

        self::assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('<script nonce="', $body);

        self::assertSame(
            1,
            preg_match('/<script nonce="([^"]*)"/', $body, $matches),
            'no nonced <script> on the page, so the copy button gets no listener under an enforced policy',
        );
        self::assertNotSame('', $matches[1], 'the nonce attribute is empty, which authorises nothing');
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * A small but genuine RFC822 message: CRLF line endings, a MIME structure,
     * and both an alternative text and an HTML part. The HTML part is the point
     * — it is the thing plMail stores decoded and separately, and therefore the
     * thing a source rebuilt from stored columns has no way back to.
     */
    private static function rawMessage(): string
    {
        return implode("\r\n", [
            'From: Original Sender <sender@example.test>',
            'To: recipient@example.test',
            'Subject: Raw bytes, not a reconstruction',
            'Message-ID: <raw-fixture@example.test>',
            'Date: Tue, 02 Sep 2025 09:15:00 +0200',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary=' . self::BOUNDARY,
            '',
            '--' . self::BOUNDARY,
            'Content-Type: text/plain; charset=utf-8',
            'Content-Transfer-Encoding: 7bit',
            '',
            'the plain text part, which a reconstruction also has',
            '',
            '--' . self::BOUNDARY,
            'Content-Type: text/html; charset=utf-8',
            'Content-Transfer-Encoding: 7bit',
            '',
            '<html><body><p>' . self::HTML_MARKER . '</p></body></html>',
            '',
            '--' . self::BOUNDARY . '--',
            '',
        ]);
    }

    /**
     * Written through the resolver rather than by hand, so the fixture and the
     * sync path agree on where a raw message lives — a test that laid the file
     * out itself would keep passing after the storage layout changed under it.
     */
    private function storeRaw(Message $message, string $content): void
    {
        $this->rawResolver->store($message, $content);
        $this->em->flush();

        self::assertNotNull($message->rawPath, 'the resolver stored nothing to serve');

        $this->storedRawPaths[] = $message->rawPath;
    }

    /**
     * Deliberately carrying a FLATTENED header map and a text body, which is
     * everything the reconstruction has to work with. It is what the first test
     * would fall back to, and what the second test actually renders.
     */
    private function seedMessage(): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->subject        = 'Raw bytes, not a reconstruction';
        $message->fromAddress    = 'sender@example.test';
        $message->fromName       = 'Original Sender';
        $message->messageId      = 'raw-fixture@example.test';
        $message->receivedAt     = new \DateTimeImmutable('-1 hour');
        $message->hasAttachments = false;
        $message->headers        = [
            'from'         => 'Original Sender <sender@example.test>',
            'to'           => 'recipient@example.test',
            'subject'      => 'Raw bytes, not a reconstruction',
            'content-type' => 'multipart/alternative',
        ];
        $message->bodyText       = 'the plain text part, which a reconstruction also has';
        $message->bodyHtml       = '<p>' . self::HTML_MARKER . '</p>';

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function signIn(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container         = static::getContainer();
        $this->em          = $container->get(EntityManagerInterface::class);
        $this->connection  = $container->get(Connection::class);
        $this->rawResolver = $container->get(RawMessageResolver::class);
        $this->rawStorage  = $container->get(RawMessageStorage::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();

        $client->loginUser($this->user);

        return $client;
    }

    private function seedAccount(): Account
    {
        $account                 = new Account();
        $account->usr            = $this->user;
        $account->name           = 'Source Fixture';
        $account->email          = 'source@example.test';
        $account->username       = 'source-' . uniqid('', true) . '@example.test';
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'password';
        $account->isActive       = true;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = 'source-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Source';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
