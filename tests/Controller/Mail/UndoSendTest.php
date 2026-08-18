<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Interface\MailSenderInterface;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Handler\SendMessageHandler;
use App\Infrastructure\Messaging\Message\SendMessageMessage;
use App\Repository\Mail\MessageRepository;
use App\Repository\User\UserRepository;
use App\Service\Mail\MailSenderRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * Undo send, proved at the sender rather than at the flag.
 *
 * The suite was green while this was broken, and the reason is worth keeping:
 * the only coverage undo had asserted that the composer reopened and that
 * `cancelled` had been set. Both were true in the failure. What nobody asked
 * was whether a mail had gone out — and one had.
 *
 * The failure, reproduced in a browser against a real worker and a real SMTP
 * server: click cancel at 9.9s of the ten-second hold, get HTTP 200 and "send
 * cancelled", and watch the message arrive at the SMTP server anyway. The row
 * was left saying both things at once — `cancelled = true` next to a populated
 * `sent_at`. SendMessageHandler READ the flag and then sent, so a cancel that
 * committed between those two steps was overwritten by events, and nothing on
 * either side could tell that it had been.
 *
 * So every test here ends at a sender that records what it was given. A test
 * that stops at `cancelled` cannot tell the fix from the bug.
 */
final class UndoSendTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private EntityManagerInterface $em;
    private Connection $connection;

    /** @var list<Email> */
    private array $sent = [];

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The whole point, end to end: cancel while the hold is running, and
     * nothing reaches the sender when the envelope comes due.
     */
    public function testACancelledSendNeverReachesTheSender(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'undo@example.test');

        $id = $this->composeAndSend($client, $account);

        $client->request('POST', '/compose/undo/' . $id);

        self::assertResponseIsSuccessful();

        // The composer coming back IS the confirmation. There used to be a
        // "Send cancelled" toast beside it, and this asserted on that string —
        // but only the dock ever raised one, the inline cancel answered with
        // the draft alone, and the window returning with everything in it says
        // more than the line did. So the assertion is on the thing that
        // matters: a cancel that won hands the composer back.
        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString(
            'compose-window',
            $body,
            'the cancel won, so the composer should come back',
        );
        self::assertStringNotContainsString(
            'Too late',
            $body,
            'the cancel won — it must not be reported as having lost',
        );

        // The envelope still comes due — cancelling does not unqueue anything.
        $this->runHandler($id);

        self::assertSame([], $this->sent, 'a cancelled send must not reach the sender');

        $message = $this->reload($id);

        self::assertNull($message->sentAt, 'a cancelled send must not be marked sent');
        self::assertFalse(
            $message->cancelled,
            'the one-shot flag is lowered again, so the next genuine send is not swallowed',
        );
    }

    /**
     * The draft survives the cancel and is still a draft.
     *
     * Half of the original report was that undo left an unsaved window over a
     * delivered mail. The delivery is covered above; this is the other half —
     * whatever the composer puts back on screen, the message is a real draft
     * on the server, findable in the Drafts list, not something that exists
     * only in the browser.
     */
    public function testACancelledSendIsLeftAsARealDraft(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'undo-draft@example.test');

        $id = $this->composeAndSend($client, $account);

        $client->request('POST', '/compose/undo/' . $id);
        $this->runHandler($id);

        $message = $this->reload($id);

        self::assertNull($message->sentAt);
        self::assertNull(
            $message->submissionSendAt,
            'the hold is lifted, so no client keeps reporting the submission as pending',
        );
        self::assertContains(
            'drafts',
            array_map(
                static fn ($label): ?string => $label->role?->value,
                $message->labels->toArray(),
            ),
            'the cancelled message is still filed as a draft',
        );
    }

    /**
     * The race itself, run deliberately rather than waited for.
     *
     * The sender calls the cancel WHILE it is being asked to send, which is
     * exactly the interleaving that used to lose: the handler has the message,
     * the undo request arrives. The cancel must be refused — and refused is
     * the operative word. Before the fix it "succeeded", set the flag on a
     * message that was already going out, and the composer confirmed it.
     */
    public function testACancelArrivingDuringTheSendIsRefused(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'undo-race@example.test');

        $id = $this->composeAndSend($client, $account);

        $repository = static::getContainer()->get(MessageRepository::class);
        $cancelWon  = null;

        // Fired from inside the send, so the ordering under test is the real
        // one and not a sleep.
        $this->onSend = static function () use ($repository, $id, &$cancelWon): void {
            $cancelWon = $repository->cancelSend($id);
        };

        $this->runHandler($id);

        self::assertFalse(
            $cancelWon,
            'a cancel that arrives after the handler claimed the message must lose, and know it',
        );
        self::assertCount(1, $this->sent, 'the send was legitimate and happened exactly once');

        $message = $this->reload($id);

        self::assertNotNull($message->sentAt);
        self::assertFalse(
            $message->cancelled,
            'a lost cancel must not leave a cancelled flag standing on a sent message',
        );
    }

    /**
     * And the user is told. This is the response that used to be a lie.
     */
    public function testTheComposerSaysTooLateWhenTheSendIsAlreadyClaimed(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'undo-late@example.test');

        $id = $this->composeAndSend($client, $account);

        // Exactly what SendMessageHandler does first, and all it takes for the
        // cancel to be too late.
        self::assertTrue(
            static::getContainer()->get(MessageRepository::class)->claimForSend($id),
        );

        $client->request('POST', '/compose/undo/' . $id);

        self::assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Too late', $body);
        self::assertStringNotContainsString(
            'Send cancelled',
            $body,
            'confirming a cancellation that did not happen is the bug this file exists for',
        );
    }

    /**
     * A send that fails puts the message down again.
     *
     * Without this the claim would outlive the attempt and every messenger
     * retry — and every later resubmission — would be refused by our own
     * bookkeeping, turning one transport blip into a draft that can never be
     * sent again.
     */
    public function testAFailedSendReleasesItsClaim(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'undo-retry@example.test');

        $id = $this->composeAndSend($client, $account);

        $this->sendSucceeds = false;
        $this->runHandler($id);

        self::assertNull($this->reload($id)->sendClaimedAt, 'a failed send releases its claim');

        // Which is what makes the retry a real second attempt.
        $this->sendSucceeds = true;
        $this->runHandler($id);

        self::assertNotNull($this->reload($id)->sentAt, 'the retry could claim and send');
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** @var (callable(): void)|null */
    private $onSend = null;

    private bool $sendSucceeds = true;

    /** Compose a draft through the composer and press Send. Returns its id. */
    private function composeAndSend(KernelBrowser $client, Account $account): int
    {
        $crawler = $client->request('GET', '/compose/new');
        $token   = $crawler->filter('input[name="compose[_token]"]')->attr('value');

        $client->request('POST', '/compose/send', [
            'compose' => [
                '_token'      => $token,
                'account'     => $account->id . '|' . $account->email,
                'toAddresses' => ['rike@example.test'],
                'subject'     => 'QA undo',
                'bodyHtml'    => '<p>MARKER must not be sent.</p>',
            ],
        ]);

        self::assertResponseIsSuccessful();

        $this->em->clear();

        $message = $this->em->createQuery(
            'SELECT m FROM ' . Message::class . ' m WHERE m.account = :account ORDER BY m.id DESC',
        )->setParameter('account', $account->id)->setMaxResults(1)->getOneOrNullResult();

        self::assertInstanceOf(Message::class, $message, 'the send saved a draft');

        return (int) $message->id;
    }

    /** Run the real handler, as the worker would when the delay expires. */
    private function runHandler(int $id): void
    {
        $this->em->clear();

        $handler = static::getContainer()->get(SendMessageHandler::class);
        $handler(new SendMessageMessage($id));

        $this->em->clear();
    }

    private function reload(int $id): Message
    {
        $this->em->clear();

        $message = $this->em->find(Message::class, $id);

        self::assertInstanceOf(Message::class, $message);

        return $message;
    }

    // ── fixture ───────────────────────────────────────────────────────────

    private function boot(KernelBrowser $client): User
    {
        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        // The sender is the assertion surface for this whole file, so it is
        // swapped before anything can resolve the real one.
        $container->set(MailSenderRegistry::class, new MailSenderRegistry([$this->recordingSender()]));

        $client->loginUser($user);
        $client->disableReboot();

        $this->connection->beginTransaction();

        $this->em->createQuery(
            'UPDATE ' . Account::class . ' a SET a.isActive = false WHERE a.usr = :usr',
        )->setParameter('usr', $user)->execute();

        $this->em->clear();

        return $this->em->find(User::class, $user->id);
    }

    private function account(User $user, string $email): Account
    {
        $account = new Account();

        $account->usr       = $user;
        $account->name      = $email;
        $account->username  = $email;
        $account->email     = $email;
        $account->authType  = 'password';
        $account->isActive  = true;
        $account->isPrimary = true;
        $account->sortOrder = 0;
        $account->imapHost  = 'imap.example.test';

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    /**
     * Records every Email it is handed, and nothing else.
     *
     * filesSentCopy() answers true so MessageSendService does not go looking
     * for an IMAP Sent folder — there is no server here, and the question under
     * test is only ever "was this handed to a sender at all".
     */
    private function recordingSender(): MailSenderInterface
    {
        $test = $this;

        return new class ($test) implements MailSenderInterface {
            public function __construct(private readonly UndoSendTest $test)
            {
            }

            public function supports(Account $account): bool
            {
                return true;
            }

            public function filesSentCopy(): bool
            {
                return true;
            }

            public function send(Email $email, Account $account): bool
            {
                return $this->test->recordSend($email);
            }
        };
    }

    /** @internal called by the recording sender above */
    public function recordSend(Email $email): bool
    {
        ($this->onSend ?? static fn () => null)();

        if (false === $this->sendSucceeds) {
            return false;
        }

        $this->sent[] = $email;

        return true;
    }
}
