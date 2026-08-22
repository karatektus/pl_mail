<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * In Sent, the who column names the recipient.
 *
 * It named the sender — which in a folder of mail you sent is your own name on
 * every row, and therefore the one fact that tells no two rows apart. It was
 * not even consistent about it: rows read "ich" or the account's display name
 * depending on whether the participant list had learned one, so the same person
 * appeared under two spellings in a single list.
 *
 * The Drafts list has always got this right — "An: uptime@joder.dev" — which is
 * where the answer came from, and why this is a scope flag on the row rather
 * than a second row template.
 *
 * A functional test rather than a browser one, deliberately: reaching Sent
 * through the interface means actually sending, and the browser stack has no
 * SMTP — the message would sit on the export queue and never arrive. What is
 * under test is what the row renders from a message that IS in Sent, and that
 * is reachable directly.
 */
final class SentListColumnTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private EntityManagerInterface $em;
    private Connection $connection;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        // The client boots the kernel, and WebTestCase refuses a second boot —
        // so it is created here rather than per test, and the fixtures are
        // seeded through its container.
        $this->client = static::createClient();

        // Without this the kernel is rebuilt between requests, taking the
        // connection holding the transaction with it, and the fixtures vanish
        // before the request can see them.
        $this->client->disableReboot();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheSentListNamesWhoTheMailWentTo(): void
    {
        $client = $this->fixtureClient();

        $sent   = $this->seedLabel('Sent', LabelRole::Sent);
        $thread = $this->thread('QA-Sent recipient column');

        // What a sent message carries and an incoming one does not.
        $message              = $thread->messages->first();
        $message->toAddresses = [['name' => 'Katharina Weber', 'address' => 'k@example.test']];
        $thread->addLabel($sent);
        $this->em->flush();

        $crawler = $client->request('GET', '/mail/sent');

        self::assertResponseIsSuccessful();

        $row = $crawler->filter('#message-list li')->text();

        self::assertStringContainsString(
            'Katharina Weber',
            $row,
            'the Sent row does not name who the mail went to',
        );
    }

    /**
     * The address when there is no name, rather than nothing.
     *
     * Most sent mail has no display name for its recipient — it was typed as an
     * address — so this is the common case rather than the edge one, and
     * falling back to an empty column here would have left the change looking
     * like it worked while fixing only the minority of rows.
     */
    public function testAnUnnamedRecipientIsListedByAddress(): void
    {
        $client = $this->fixtureClient();

        $sent   = $this->seedLabel('Sent', LabelRole::Sent);
        $thread = $this->thread('QA-Sent bare address');

        $message              = $thread->messages->first();
        $message->toAddresses = [['name' => '', 'address' => 'bare@example.test']];
        $thread->addLabel($sent);
        $this->em->flush();

        $crawler = $client->request('GET', '/mail/sent');

        self::assertStringContainsString('bare@example.test', $crawler->filter('#message-list li')->text());
    }

    /**
     * And the inbox is untouched.
     *
     * The scope flag is per list, so the risk of this change is that the WRONG
     * list starts answering "to" — which would replace the sender with your own
     * address on every incoming mail and be far worse than the bug being fixed.
     */
    public function testTheInboxStillNamesTheSender(): void
    {
        $client = $this->fixtureClient();

        $this->thread('QA-Inbox sender column');

        $crawler = $client->request('GET', '/mail/inbox');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'sender@example.test',
            $crawler->filter('#message-list li')->text(),
        );
    }

    private function fixtureClient(): KernelBrowser
    {
        return $this->client;
    }
}
