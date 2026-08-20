<?php

declare(strict_types=1);

namespace App\Tests\Controller\Insight;

use App\Entity\Insight\InsightReport;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Reporting a mail that should have produced an insight — over real HTTP,
 * because what this endpoint does is copy somebody's mail into a table their
 * administrator can export, and every claim worth making about it is a claim
 * about the request.
 *
 * The claims:
 *   - a report freezes a SNAPSHOT of the mail rather than a pointer to it, and
 *     the body is cut to InsightReport::MAX_BODY_CHARS on the way in;
 *   - the note is kept, trimmed and cut to MAX_NOTE_CHARS;
 *   - a second report by the same person corrects the first instead of filing
 *     a second row (the guard is InsightReportRepository
 *     ::findOneByMessageAndReporter, and this is what proves it is wired);
 *   - a POST with no token, or the wrong one, writes nothing;
 *   - **user B cannot report user A's mail.** That case is the reason the
 *     controller runs OwnershipVoter before it reads a single field: without
 *     it, posting a stranger's message id is a way to have their mail
 *     delivered, in full, to an admin panel. It is asserted against a real
 *     second user's real message id, so a missing check answers 200 and fails
 *     loudly rather than 404-ing its way to a pass — the standard
 *     CrossUserIsolationTest holds itself to.
 *
 * Fixtures follow RadarPanelTest: a fresh user inside a transaction that is
 * never committed, rather than the seeded e2e user whose mailbox this suite
 * would then have to leave as it found it. Nothing is selected by a translated
 * string; the token is read off the dialog by the data attribute the template
 * stamps, which is also where the real button gets it.
 */
final class InsightReportTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;
    private Account $account;
    private DateTimeImmutable $now;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAReportFreezesTheMailRatherThanPointingAtIt(): void
    {
        $client  = $this->signIn();
        $message = $this->message();

        $this->report($client, $message, 'Sendungsnummer steht ganz unten');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['ok' => true, 'alreadyReported' => false],
            json_decode((string) $client->getResponse()->getContent(), true),
        );

        $report = $this->onlyReport();

        self::assertSame($message->id, $report->message?->id);
        self::assertSame($this->user->id, $report->reportedBy?->id);
        self::assertSame($this->account->id, $report->account?->id);
        self::assertSame('shop@example.test', $report->fromAddress);
        self::assertSame('Example Shop', $report->fromName);
        self::assertSame('Your order has shipped', $report->subject);
        self::assertSame(
            $message->receivedAt?->getTimestamp(),
            $report->receivedAt?->getTimestamp(),
            'the report kept the time it was filed rather than the time the mail arrived',
        );
    }

    /**
     * The body is a sample, not an archive — the entity says why — so the
     * quoted thread below the shape does not travel.
     */
    public function testTheBodyIsCutToTheSnapshotLength(): void
    {
        $client = $this->signIn();

        $body    = str_repeat('a', InsightReport::MAX_BODY_CHARS + 500);
        $message = $this->message(bodyText: $body);

        $this->report($client, $message);

        $report = $this->onlyReport();

        self::assertNotNull($report->bodyText);
        self::assertSame(InsightReport::MAX_BODY_CHARS, mb_strlen($report->bodyText));
    }

    /** Never the HTML part, whatever the mail carries. */
    public function testTheHtmlPartIsNeverCopied(): void
    {
        $client = $this->signIn();

        $message            = $this->message(bodyText: 'Tracking: PKG-1');
        $message->bodyHtml  = '<p>Tracking: <b>PKG-1</b></p>';
        $this->em->flush();

        $this->report($client, $message);

        self::assertSame('Tracking: PKG-1', $this->onlyReport()->bodyText);
    }

    public function testTheNoteIsTrimmedAndCutToItsOwnLength(): void
    {
        $client  = $this->signIn();
        $message = $this->message();

        $this->report($client, $message, '   ' . str_repeat('b', InsightReport::MAX_NOTE_CHARS + 80) . '   ');

        $note = $this->onlyReport()->note;

        self::assertNotNull($note);
        self::assertSame(InsightReport::MAX_NOTE_CHARS, mb_strlen($note));
        self::assertSame('b', mb_substr($note, 0, 1), 'the surrounding whitespace was stored');
    }

    /** A blank note is no note at all, rather than an empty string in the export. */
    public function testAnEmptyNoteIsStoredAsNothing(): void
    {
        $client = $this->signIn();

        $this->report($client, $this->message(), '   ');

        self::assertNull($this->onlyReport()->note);
    }

    public function testASecondReportCorrectsTheFirstRatherThanFilingAgain(): void
    {
        $client  = $this->signIn();
        $message = $this->message();

        $this->report($client, $message, 'das ist eine Rechnung');
        $this->report($client, $message, 'und sie ist am 3. fällig');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['ok' => true, 'alreadyReported' => true],
            json_decode((string) $client->getResponse()->getContent(), true),
        );

        self::assertSame(1, $this->countReports(), 'the second report filed a second row');
        self::assertSame('und sie ist am 3. fällig', $this->onlyReport()->note);
    }

    /** Confirming without typing anything keeps what was said the first time. */
    public function testASecondReportWithoutANoteKeepsTheFirstOne(): void
    {
        $client  = $this->signIn();
        $message = $this->message();

        $this->report($client, $message, 'das ist eine Rechnung');
        $this->report($client, $message);

        self::assertSame(1, $this->countReports());
        self::assertSame('das ist eine Rechnung', $this->onlyReport()->note);
    }

    public function testATokenlessReportWritesNothing(): void
    {
        $client  = $this->signIn();
        $message = $this->message();

        $client->request('POST', "/insights/report/{$message->id}");

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->countReports(), 'a tokenless POST filed a report');
    }

    public function testAReportWithTheWrongTokenWritesNothing(): void
    {
        $client  = $this->signIn();
        $message = $this->message();

        $client->request(
            'POST',
            "/insights/report/{$message->id}",
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X-CSRF-Token' => 'not-the-token'],
            content: '{}',
        );

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->countReports(), 'a forged token filed a report');
    }

    /**
     * The security case. A valid token proves the page, not the ownership — and
     * a report of somebody else's mail is a copy of it in an admin's export.
     */
    public function testAStrangersMailCannotBeReported(): void
    {
        $client = $this->signIn();

        // Minted on the reporter's OWN mail, so the request that follows is a
        // genuinely authenticated one carrying a genuinely valid token.
        $token = $this->token($client, $this->message());

        $stranger = $this->seedUser('report-stranger-');
        $mailbox  = $this->seedAccount($stranger);
        $foreign  = $this->message($mailbox, 'Not your mail');

        $client->request(
            'POST',
            "/insights/report/{$foreign->id}",
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X-CSRF-Token' => $token],
            content: json_encode(['note' => 'give me that mail']),
        );

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->countReports(), "a stranger's mail was copied into a report");
    }

    /** And the dialog behind the menu entry refuses the same id. */
    public function testTheDialogRefusesAStrangersMail(): void
    {
        $client = $this->signIn();

        $stranger = $this->seedUser('report-stranger-');
        $foreign  = $this->message($this->seedAccount($stranger), 'Not your mail');

        $client->request('GET', "/insights/report/{$foreign->id}");

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    /** The dialog says where the mail goes, every time it is opened. */
    public function testTheDialogDisclosesWhereTheMailGoes(): void
    {
        $client  = $this->signIn();
        $message = $this->message();

        $crawler = $client->request('GET', "/insights/report/{$message->id}");

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'administration',
            mb_strtolower($crawler->filter('[data-controller="insight--report"]')->text()),
            'the consent sentence is not on the dialog',
        );
    }

    // ── Requests ──────────────────────────────────────────────────────────

    /** The report as the button files it: JSON body, token in the header. */
    private function report(KernelBrowser $client, Message $message, ?string $note = null): void
    {
        $client->request(
            'POST',
            "/insights/report/{$message->id}",
            server: [
                'CONTENT_TYPE'      => 'application/json',
                'HTTP_X-CSRF-Token' => $this->token($client, $message),
            ],
            content: (string) json_encode(null === $note ? [] : ['note' => $note]),
        );
    }

    /**
     * The per-action token, read off the dialog — which is the only place the
     * real button gets it from either (RadarPanelTest reads its own the same
     * way).
     */
    private function token(KernelBrowser $client, Message $message): string
    {
        $crawler = $client->request('GET', "/insights/report/{$message->id}");

        return (string) $crawler
            ->filter('[data-controller="insight--report"]')
            ->attr('data-insight--report-token-value');
    }

    // ── Reads ─────────────────────────────────────────────────────────────

    private function countReports(): int
    {
        $this->em->clear();

        return $this->em->getRepository(InsightReport::class)->count([]);
    }

    private function onlyReport(): InsightReport
    {
        self::assertSame(1, $this->countReports());

        $reports = $this->em->getRepository(InsightReport::class)->findAll();

        self::assertInstanceOf(InsightReport::class, $reports[0]);

        return $reports[0];
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function message(
        ?Account $account = null,
        string $subject = 'Your order has shipped',
        ?string $bodyText = 'Your parcel is on its way.',
    ): Message {
        $message                 = new Message();
        $message->account        = $account ?? $this->account;
        $message->messageId      = uniqid('report-', true) . '@example.test';
        $message->subject        = $subject;
        $message->fromAddress    = 'shop@example.test';
        $message->fromName       = 'Example Shop';
        $message->bodyText       = $bodyText;
        $message->receivedAt     = $this->now->modify('-1 hour');
        $message->sentAt         = $message->receivedAt;
        $message->seenAt         = $message->receivedAt;
        $message->flags          = [];
        $message->hasAttachments = false;

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function signIn(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->now        = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // Never committed, so the suite leaves nothing behind and can be run
        // repeatedly against the same database.
        $this->connection->beginTransaction();

        $this->user    = $this->seedUser('insight-report-');
        $this->account = $this->seedAccount($this->user);

        $client->loginUser($this->user);

        return $client;
    }

    private function seedUser(string $prefix): User
    {
        $user            = new User();
        $user->email     = $prefix . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Insight';
        $user->nameLast  = 'Reporter';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function seedAccount(User $user): Account
    {
        $account                 = new Account();
        $account->usr            = $user;
        $account->name           = 'Report fixture';
        $account->email          = uniqid('report-', true) . '@example.test';
        $account->username       = uniqid('report-', true);
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
}
