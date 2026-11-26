<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Crypto\ContactKeyOrigin;
use App\Domain\Enum\Crypto\ContactKeyStatus;
use App\Domain\Enum\Crypto\RecipientPolicy;
use App\Entity\Crypto\ContactKey;
use App\Domain\Enum\Account\EmailAliasSource;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\SendMessageMessage;
use App\Service\Mail\DraftAttachmentService;
use App\Service\Mail\ForwardAgents;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * "Forward to agent" over real HTTP: the dialog, and the send behind it.
 *
 * The claims:
 *   - a send makes a forward addressed to the chosen agent, with the note
 *     above the original, and queues it like any other send;
 *   - the note is text, never markup;
 *   - an address that is not on the sender's own list is refused, so the
 *     endpoint cannot mail a stranger;
 *   - a stranger's mail cannot be forwarded, and a send without the token
 *     queues nothing;
 *   - a send the composer would sign or encrypt is not made from here, and
 *     that counts the default Bcc as well as the agent;
 *   - the Bcc is the one of the account the forward goes from;
 *   - a forward missing an attachment is left in Drafts, not sent.
 *
 * Fixtures follow InsightReportTest: a fresh user inside a transaction that is
 * never committed.
 */
final class AgentForwardTest extends WebTestCase
{
    private const string AGENT = 'research-bot@example.test';

    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;
    private Account $account;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAForwardGoesToTheAgentWithTheNoteAboveTheOriginal(): void
    {
        $client  = $this->signIn();
        $message = $this->message();

        $this->forward($client, $message, self::AGENT, "What do you think of this?\nBe brief.");

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('turbo-stream', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('/compose/undo/', (string) $client->getResponse()->getContent());

        $draft = $this->queuedDraft();

        self::assertSame(self::AGENT, $draft->toAddresses[0]['address'] ?? null);
        self::assertSame($this->account->id, $draft->account->id);
        self::assertStringContainsString('Your parcel is on its way.', (string) $draft->bodyHtml);

        $body = (string) $draft->bodyHtml;
        $note = strpos($body, 'What do you think of this?<br />');

        self::assertNotFalse($note, 'the note is not in the forward');
        self::assertLessThan(strpos($body, 'Your parcel is on its way.'), $note, 'the note belongs above the original');

        $text = (string) $draft->bodyText;

        self::assertStringStartsWith("What do you think of this?\nBe brief.", $text);
        self::assertStringContainsString('Subject: Your order has shipped', $text, 'the text part lost the forwarded headers');
        self::assertStringContainsString('Your parcel is on its way.', $text, 'an agent reading the text part would not see the mail');
    }

    public function testTheTextPartKeepsWhereLinksGo(): void
    {
        $client  = $this->signIn();
        $message = $this->message();

        $message->bodyHtml = '<p>Your invoice: <a href="https://shop.example.test/invoice?id=1&amp;v=2">View invoice</a></p>';
        $this->em->flush();

        $this->forward($client, $message, self::AGENT, '');

        self::assertStringContainsString('View invoice <https://shop.example.test/invoice?id=1&v=2>', (string) $this->queuedDraft()->bodyText);
    }

    public function testTheTextPartKeepsPreformattedSpacing(): void
    {
        $client  = $this->signIn();
        $message = $this->message();

        $message->bodyHtml = "<p>The log:</p><pre>  line one\n    indented  twice\n\n\n\nafter gap</pre>";
        $this->em->flush();

        $this->forward($client, $message, self::AGENT, '');

        self::assertStringContainsString("  line one\n    indented  twice\n\n\n\nafter gap", (string) $this->queuedDraft()->bodyText);
    }

    public function testTheTextPartKeepsTableCellsApart(): void
    {
        $client  = $this->signIn();
        $message = $this->message();

        $message->bodyHtml = '<table><tr><td>Total</td><td>$42.00</td></tr></table>';
        $this->em->flush();

        $this->forward($client, $message, self::AGENT, '');

        self::assertStringContainsString("Total\t$42.00", (string) $this->queuedDraft()->bodyText);
    }

    public function testTheNoteIsTextNotMarkup(): void
    {
        $client = $this->signIn();

        $this->forward($client, $this->message(), self::AGENT, '<img src=x onerror=alert(1)>');

        $body = (string) $this->queuedDraft()->bodyHtml;

        self::assertStringContainsString('<p>&lt;img src', $body);
        self::assertStringNotContainsString('<img', $body);
    }

    public function testAnAddressOffTheListIsRefused(): void
    {
        $client = $this->signIn();

        $this->forward($client, $this->message(), 'stranger@example.test', 'Have a look');

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame([], $this->queued());
    }

    public function testAStrangersMailCannotBeForwarded(): void
    {
        $client = $this->signIn();
        $token  = $this->token($client, $this->message());

        $foreign = $this->message($this->seedAccount($this->seedUser()));

        $client->request(
            'POST',
            "/mail/forward-to-agent/{$foreign->id}",
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X-CSRF-Token' => $token],
            content: (string) json_encode(['agent' => self::AGENT, 'note' => '']),
        );

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame([], $this->queued());
    }

    public function testAForwardWithoutTheTokenQueuesNothing(): void
    {
        $client  = $this->signIn();
        $message = $this->message();

        $client->request(
            'POST',
            "/mail/forward-to-agent/{$message->id}",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['agent' => self::AGENT, 'note' => '']),
        );

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame([], $this->queued());
    }

    public function testTheDialogListsTheAgentsInOrder(): void
    {
        $client = $this->signIn();
        static::getContainer()->get(ForwardAgents::class)->add($this->user, 'Inbox helper', 'helper@example.test');
        $this->em->flush();

        $crawler = $client->request('GET', '/mail/forward-to-agent/' . $this->message()->id);

        self::assertResponseIsSuccessful();
        self::assertSame(
            [self::AGENT, 'helper@example.test'],
            $crawler->filter('input[name="agent"]')->each(static fn ($input): string => (string) $input->attr('value')),
        );
        self::assertNotNull($crawler->filter('input[name="agent"]')->first()->attr('checked'));
    }

    public function testWhenEverythingSentIsSignedNothingIsSentFromHere(): void
    {
        $client  = $this->signIn();
        $message = $this->message();
        $token   = $this->token($client, $message);

        $this->user->signPlainMail = true;
        $this->em->flush();

        $crawler = $client->request('GET', "/mail/forward-to-agent/{$message->id}");

        self::assertCount(0, $crawler->filter('[data-controller="mail--agent-forward"]'), 'the dialog offered a send the composer would have signed');

        $client->request(
            'POST',
            "/mail/forward-to-agent/{$message->id}",
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X-CSRF-Token' => $token],
            content: (string) json_encode(['agent' => self::AGENT, 'note' => '']),
        );

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame([], $this->queued());
    }

    public function testAnEncryptableDefaultBccStopsTheForward(): void
    {
        $client  = $this->signIn();
        $message = $this->message();
        $token   = $this->token($client, $message);

        $this->account->setSetting(Account::SETTING_DEFAULT_BCC, ['archive@example.test']);
        $this->em->persist(new ContactKey($this->user, 'archive@example.test', str_repeat('d', 40), "-----BEGIN PGP PUBLIC KEY BLOCK-----\n\nx\n-----END PGP PUBLIC KEY BLOCK-----", ContactKeyOrigin::Manual, ContactKeyStatus::Current, null, new DateTimeImmutable()));
        $this->em->flush();

        $crawler = $client->request('GET', "/mail/forward-to-agent/{$message->id}");

        self::assertCount(0, $crawler->filter('[data-controller="mail--agent-forward"]'), 'the dialog offered a send the composer would have encrypted to the Bcc');

        $client->request(
            'POST',
            "/mail/forward-to-agent/{$message->id}",
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X-CSRF-Token' => $token],
            content: (string) json_encode(['agent' => self::AGENT, 'note' => '']),
        );

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame([], $this->queued());
    }

    public function testAnAgentWithAKeyCannotBePickedEvenWhenNeverEncrypted(): void
    {
        $client  = $this->signIn();
        $message = $this->message();
        $token   = $this->token($client, $message);

        $key          = new ContactKey($this->user, self::AGENT, str_repeat('d', 40), "-----BEGIN PGP PUBLIC KEY BLOCK-----\n\nx\n-----END PGP PUBLIC KEY BLOCK-----", ContactKeyOrigin::Manual, ContactKeyStatus::Current, null, new DateTimeImmutable());
        $key->encrypt = RecipientPolicy::Never;
        $this->em->persist($key);
        $this->em->flush();

        $crawler = $client->request('GET', "/mail/forward-to-agent/{$message->id}");

        self::assertNotNull($crawler->filter('input[name="agent"]')->first()->attr('disabled'), 'the composer would sign mail to this agent');

        $client->request(
            'POST',
            "/mail/forward-to-agent/{$message->id}",
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X-CSRF-Token' => $token],
            content: (string) json_encode(['agent' => self::AGENT, 'note' => '']),
        );

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame([], $this->queued());
    }

    public function testMailToAnAliasIsJudgedByTheAlias(): void
    {
        $client = $this->signIn();

        $this->account->addAlias(new EmailAlias($this->account, 'alias@example.test', EmailAliasSource::Manual));
        $this->user->ownAddressEncrypt = [(string) $this->account->email => 'always'];
        $this->em->flush();

        $message              = $this->message();
        $message->toAddresses = [['name' => null, 'address' => 'alias@example.test']];
        $this->em->flush();

        $this->forward($client, $message, self::AGENT, '');

        self::assertResponseIsSuccessful();
        self::assertSame('alias@example.test', $this->queuedDraft()->fromAddress);
    }

    public function testTheBccIsTheOneOfTheAccountTheForwardGoesFrom(): void
    {
        $client = $this->signIn();

        $receiveOnly              = $this->seedAccount($this->user);
        $receiveOnly->sendEnabled = false;
        $receiveOnly->setSetting(Account::SETTING_DEFAULT_BCC, ['old-archive@example.test']);
        $this->account->setSetting(Account::SETTING_DEFAULT_BCC, ['archive@example.test']);
        $this->em->flush();

        $this->forward($client, $this->message($receiveOnly), self::AGENT, '');

        $draft = $this->queuedDraft();

        self::assertSame($this->account->id, $draft->account->id);
        self::assertSame(['archive@example.test'], array_column($draft->bccAddresses ?? [], 'address'));
    }

    public function testAForwardMissingAnAttachmentIsLeftInDrafts(): void
    {
        $client  = $this->signIn();
        $message = $this->message();

        $part              = new MessagePart();
        $part->contentType = 'application/pdf';
        $part->filename    = 'invoice.pdf';
        $part->disposition = 'attachment';
        $part->isInline    = false;
        $part->size        = DraftAttachmentService::MAX_BYTES + 1;
        $part->storagePath = 'var/agent-forward/invoice.pdf';
        $message->addMessagePart($part);
        $message->hasAttachments = true;
        $this->em->persist($part);
        $this->em->flush();

        $this->forward($client, $message, self::AGENT, 'Pay this?');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('in your Drafts', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('/compose/undo/', (string) $client->getResponse()->getContent());
        self::assertSame([], $this->queued());
    }

    public function testWithoutAgentsTheDialogPointsToSettings(): void
    {
        $client = $this->signIn(withAgent: false);

        $crawler = $client->request('GET', '/mail/forward-to-agent/' . $this->message()->id);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-controller="mail--agent-forward"]'));
        self::assertCount(1, $crawler->filter('a[href$="#card-forward-agents"]'));
    }

    // ── Requests ──────────────────────────────────────────────────────────

    private function forward(KernelBrowser $client, Message $message, string $agent, string $note): void
    {
        $client->request(
            'POST',
            "/mail/forward-to-agent/{$message->id}",
            server: [
                'CONTENT_TYPE'      => 'application/json',
                'HTTP_X-CSRF-Token' => $this->token($client, $message),
            ],
            content: (string) json_encode(['agent' => $agent, 'note' => $note]),
        );
    }

    private function token(KernelBrowser $client, Message $message): string
    {
        $crawler = $client->request('GET', "/mail/forward-to-agent/{$message->id}");

        return (string) $crawler
            ->filter('[data-controller="mail--agent-forward"]')
            ->attr('data-mail--agent-forward-token-value');
    }

    // ── Reads ─────────────────────────────────────────────────────────────

    /** @return list<SendMessageMessage> */
    private function queued(): array
    {
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.export');

        $sends = [];

        foreach ($transport->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof SendMessageMessage) {
                $sends[] = $envelope->getMessage();
            }
        }

        return $sends;
    }

    private function queuedDraft(): Message
    {
        $sends = $this->queued();

        self::assertCount(1, $sends);

        $this->em->clear();
        $draft = $this->em->find(Message::class, $sends[0]->messageId);

        self::assertInstanceOf(Message::class, $draft);

        return $draft;
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function message(?Account $account = null): Message
    {
        $now = new DateTimeImmutable('-1 hour', new DateTimeZone('UTC'));

        $message                 = new Message();
        $message->account        = $account ?? $this->account;
        $message->messageId      = uniqid('agent-forward-', true) . '@example.test';
        $message->subject        = 'Your order has shipped';
        $message->fromAddress    = 'shop@example.test';
        $message->fromName       = 'Example Shop';
        $message->bodyText       = 'Your parcel is on its way.';
        $message->bodyHtml       = '<p>Your parcel is on its way.</p>';
        $message->receivedAt     = $now;
        $message->sentAt         = $now;
        $message->seenAt         = $now;
        $message->flags          = [];
        $message->hasAttachments = false;

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function signIn(bool $withAgent = true): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount($this->user);

        if (true === $withAgent) {
            $container->get(ForwardAgents::class)->add($this->user, 'Research bot', self::AGENT);
            $this->em->flush();
        }

        $client->loginUser($this->user);

        return $client;
    }

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = uniqid('agent-forward-', true) . '@example.test';
        $user->nameFirst = 'Agent';
        $user->nameLast  = 'Forwarder';
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
        $account->name           = 'Agent forward fixture';
        $account->email          = uniqid('agent-forward-', true) . '@example.test';
        $account->username       = uniqid('agent-forward-', true);
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'password';
        $account->mailEnabled    = true;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }
}
