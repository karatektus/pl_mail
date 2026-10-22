<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Enum\Mail\SendFailure;
use App\Repository\Mail\MessageRepository;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A draft whose send finally failed wears a red warning triangle in the
 * Drafts list, and the Drafts entry in the sidebar wears the same one while
 * any draft does (#125).
 *
 * The toast that says a send failed is gone in twelve seconds, and a scheduled
 * send fails with nobody watching; these two marks are what is left to find.
 */
final class UnsentDraftWarningTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private Connection $connection;

    protected function tearDown(): void
    {
        if (isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAFailedDraftIsMarkedInTheListAndInTheSidebar(): void
    {
        $client = $this->fixtureClient();
        $id     = $this->seedDraft('Lunch on Friday?');

        static::getContainer()->get(MessageRepository::class)->markSendFailed($id, SendFailure::Refused);

        // Written by SQL, as the handler writes it; the request shares this
        // entity manager, which still holds the draft from before.
        $this->em->clear();

        $crawler = $client->request('GET', '/mail/drafts');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Lunch on Friday?', (string) $client->getResponse()->getContent(), 'the draft is listed');

        $row = $crawler->filter('[data-unsent-draft]');
        self::assertCount(1, $row, 'the draft row carries the warning');
        self::assertSame('Not sent: the mail server refused it', $row->attr('aria-label'), 'named by its reason');

        $mark = $crawler->filter('[data-unsent-mark]')->first();
        self::assertStringNotContainsString('hidden', (string) $mark->attr('class'), 'and Drafts in the sidebar shows it');
        self::assertCount(1, $mark->filter('i.fa-triangle-exclamation'), 'as the same triangle');

        self::assertSame(1, $this->counts($client)['unsent:role:drafts'], 'and the live counts agree');
    }

    public function testADraftThatWasNeverSentCarriesNoWarning(): void
    {
        $client = $this->fixtureClient();
        $this->seedDraft('Just a draft');

        $crawler = $client->request('GET', '/mail/drafts');
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('[data-unsent-draft]'));

        $mark = $crawler->filter('[data-unsent-mark]')->first();
        self::assertStringContainsString('hidden', (string) $mark->attr('class'), 'rendered, but hidden at zero');
        self::assertStringNotContainsString('fa-', (string) $mark->attr('class'),
            'and hidden by an element Font Awesome does not style: its .fa-solid sets display after '
            . 'Tailwind, so `hidden` on the icon itself does not hide it');
        self::assertDoesNotMatchRegularExpression('/(^|\s)(inline-flex|inline-block|flex|block|inline|grid)(\s|$)/', (string) $mark->attr('class'),
            'and with no other display utility competing with `hidden`');
        self::assertSame(0, $this->counts($client)['unsent:role:drafts']);
    }

    private function seedDraft(string $subject): int
    {
        $drafts = $this->seedLabel('Drafts', LabelRole::Drafts);
        $thread = $this->thread($subject, flush: false);

        $thread->addLabel($drafts);

        $message = $thread->messages->first();
        self::assertNotFalse($message);
        $message->flags  = [MessageFlag::DRAFT->value];
        $message->sentAt = null;
        $message->labels->add($drafts);

        $this->em->flush();

        return (int) $message->id;
    }

    private function fixtureClient(): KernelBrowser
    {
        $client = static::createClient();

        // Without this the kernel is rebuilt between requests, taking the
        // connection holding the transaction with it.
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);

        $client->loginUser($this->user);

        return $client;
    }

    /** @return array<string, int|string> */
    private function counts(KernelBrowser $client): array
    {
        $client->request('GET', '/mail/sidebar/counts');
        self::assertResponseIsSuccessful();

        return json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
