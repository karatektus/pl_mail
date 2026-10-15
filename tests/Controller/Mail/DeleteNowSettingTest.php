<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Deleting for good is the user's choice, and it is off until they make it.
 *
 * Settings → Mail → Disposal holds one switch, "delete-now buttons", and it
 * decides whether the row button and the toolbar button above a selection are
 * offered at all. The claim worth pinning is that it is a gate and not only a
 * hidden button: a switch that merely hid the flame would leave both routes
 * answering a hand-made POST, which is the one thing the switch is for.
 *
 * Every refusal here has its companion in the same test — the same request,
 * after the switch is turned on, succeeds. An empty answer alone cannot tell a
 * gate from a route that is broken.
 */
final class DeleteNowSettingTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;
    private Label $spam;
    private Label $trash;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);
        $this->spam    = $this->seedLabel('Spam', LabelRole::Spam);
        $this->trash   = $this->seedLabel('Trash', LabelRole::Trash);

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheSwitchIsOffUntilTheUserTurnsItOn(): void
    {
        self::assertFalse($this->user->deleteNowButtons);
        self::assertNull($this->user->getSetting(User::SETTING_DISPOSAL_DELETE_NOW), 'nothing is stored for the default');

        $this->user->deleteNowButtons = true;

        self::assertTrue($this->user->deleteNowButtons);
        self::assertTrue($this->user->getSetting(User::SETTING_DISPOSAL_DELETE_NOW));

        $this->user->deleteNowButtons = false;

        self::assertFalse($this->user->deleteNowButtons);
        self::assertNull(
            $this->user->getSetting(User::SETTING_DISPOSAL_DELETE_NOW),
            'switching it off again stores nothing for the default, as every setting in the bag does',
        );
    }

    public function testDeletingOneConversationForGoodIsRefusedWhileTheSwitchIsOffAndWorksOnceItIsOn(): void
    {
        $thread   = $this->spamThread('Spam');
        $threadId = $thread->id;

        $this->postTo(sprintf('/status/thread/%d/purge', $threadId));

        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        self::assertNotNull($this->em->find(MessageThread::class, $threadId), 'refused, so the conversation is still there');

        $this->turnTheSwitchOn();

        $this->postTo(sprintf('/status/thread/%d/purge', $threadId));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        self::assertNull($this->em->find(MessageThread::class, $threadId), 'the same request deletes it once the switch is on');
    }

    public function testDeletingASelectionForGoodIsRefusedWhileTheSwitchIsOffAndWorksOnceItIsOn(): void
    {
        $first    = $this->spamThread('Spam one');
        $second   = $this->spamThread('Spam two');
        $firstId  = $first->id;
        $secondId = $second->id;

        $this->postTo('/status/bulk/purge', ['ids' => [$firstId, $secondId]]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        self::assertNotNull($this->em->find(MessageThread::class, $firstId), 'refused, so nothing in the selection is deleted');
        self::assertNotNull($this->em->find(MessageThread::class, $secondId));

        $this->turnTheSwitchOn();

        $this->postTo('/status/bulk/purge', ['ids' => [$firstId, $secondId]]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        self::assertNull($this->em->find(MessageThread::class, $firstId), 'the same request deletes the selection once the switch is on');
        self::assertNull($this->em->find(MessageThread::class, $secondId));
    }

    public function testTheRowButtonIsOnlyOfferedInSpamAndTheBinWhileTheSwitchIsOn(): void
    {
        $this->spamThread('Spam');

        $crawler = $this->client->request('GET', '/mail/spam');
        self::assertSame(0, $crawler->filter($this->rowButton())->count(), 'off: no flame on a row in Spam');

        $this->turnTheSwitchOn();

        $crawler = $this->client->request('GET', '/mail/spam');
        self::assertSame(1, $crawler->filter($this->rowButton())->count(), 'on: the flame is on the row in Spam');
    }

    /**
     * Above a selection in Spam the Delete is the ordinary one while the switch
     * is off, and the flame in its place once it is on.
     */
    public function testTheToolbarOffersDeleteInSpamWhileTheSwitchIsOffAndDeleteForeverOnceItIsOn(): void
    {
        $this->spamThread('Spam');

        $crawler = $this->client->request('GET', '/mail/spam');
        self::assertSame(1, $crawler->filter($this->toolbarButton('deleteSelected'))->count(), 'off: the ordinary Delete');
        self::assertSame(0, $crawler->filter($this->toolbarButton('purgeSelected'))->count(), 'off: and no Delete forever');

        $this->turnTheSwitchOn();

        $crawler = $this->client->request('GET', '/mail/spam');
        self::assertSame(0, $crawler->filter($this->toolbarButton('deleteSelected'))->count(), 'on: the ordinary Delete gives way');
        self::assertSame(1, $crawler->filter($this->toolbarButton('purgeSelected'))->count(), 'on: to Delete forever');

        // The question the button asks travels with the toolbar. Without it the
        // dialog falls back to a bare "Are you sure?" that names nothing and
        // counts nothing, which is how a toolbar that was merged by hand lost
        // it once without a PHP test noticing.
        $i18n = (string) $crawler->filter('[data-controller~="mail--list-toolbar"]')->attr('data-mail--list-toolbar-i18n-value');

        self::assertStringContainsString('purgeConfirmOne', $i18n, 'the singular question is on the toolbar');
        self::assertStringContainsString('purgeConfirmMany', $i18n, 'and so is the plural one');
    }

    /**
     * The bin has no Delete above a selection while the switch is off: a trash
     * posted for mail that already carries Trash changes nothing on the server
     * and still takes the rows off the page, so the mail would look deleted and
     * be back on the next load.
     */
    public function testTheBinOffersNoDeleteAboveASelectionWhileTheSwitchIsOff(): void
    {
        $this->binThread('In the bin');

        $crawler = $this->client->request('GET', '/mail/trash');
        self::assertSame(0, $crawler->filter($this->toolbarButton('deleteSelected'))->count(), 'off: no Delete that cannot delete');
        self::assertSame(0, $crawler->filter($this->toolbarButton('purgeSelected'))->count(), 'off: and no Delete forever');

        $this->turnTheSwitchOn();

        $crawler = $this->client->request('GET', '/mail/trash');
        self::assertSame(1, $crawler->filter($this->toolbarButton('purgeSelected'))->count(), 'on: Delete forever, the presence companion');
    }

    public function testTheInboxKeepsItsDeleteWhateverTheSwitchSays(): void
    {
        $this->thread('An ordinary conversation');

        $crawler = $this->client->request('GET', '/mail/inbox');
        self::assertSame(1, $crawler->filter($this->toolbarButton('deleteSelected'))->count(), 'off');
        self::assertSame(0, $crawler->filter($this->toolbarButton('purgeSelected'))->count());

        $this->turnTheSwitchOn();

        $crawler = $this->client->request('GET', '/mail/inbox');
        self::assertSame(1, $crawler->filter($this->toolbarButton('deleteSelected'))->count(), 'on: the inbox is not somewhere mail is deleted for good');
        self::assertSame(0, $crawler->filter($this->toolbarButton('purgeSelected'))->count());
    }

    public function testTheDisposalSectionShowsTheSwitchAndSavingItStoresTheChoice(): void
    {
        $crawler = $this->client->request('GET', '/settings?section=disposal');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('input[name="deleteNow"][value="1"]')->count(), 'the switch is on the page');
        self::assertSame(0, $crawler->filter('input[name="deleteNow"][value="1"][checked]')->count(), 'and is off to begin with');

        $token = (string) $crawler->filter('form[action="/settings/disposal"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/settings/disposal', ['_token' => $token, 'deleteNow' => '1']);
        $this->em->clear();

        $user = $this->em->find(User::class, $this->user->id);
        self::assertTrue($user->deleteNowButtons, 'saved on');

        $crawler = $this->client->request('GET', '/settings?section=disposal');
        self::assertSame(1, $crawler->filter('input[name="deleteNow"][value="1"][checked]')->count(), 'and shown as on');

        $this->client->request('POST', '/settings/disposal', ['_token' => $token, 'deleteNow' => '0']);
        $this->em->clear();

        $user = $this->em->find(User::class, $this->user->id);
        self::assertFalse($user->deleteNowButtons, 'saved off again');
        self::assertNull($user->getSetting(User::SETTING_DISPOSAL_DELETE_NOW), 'by storing nothing for the default, not false');
    }

    public function testASaveWithABadTokenChangesNothing(): void
    {
        $this->client->request('POST', '/settings/disposal', ['_token' => 'not-the-token', 'deleteNow' => '1']);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        self::assertFalse($this->em->find(User::class, $this->user->id)->deleteNowButtons);
    }

    public function testTheNavigationListsDisposalUnderMail(): void
    {
        $crawler = $this->client->request('GET', '/settings?section=disposal');

        self::assertSame(
            1,
            $crawler->filter('a[href*="section=disposal"][aria-current="page"]')->count(),
            'Disposal is a section of its own, and is the one marked as current',
        );
    }

    private function turnTheSwitchOn(): void
    {
        $user = $this->em->find(User::class, $this->user->id);
        $user->deleteNowButtons = true;
        $this->em->flush();
        $this->em->clear();

        // The firewall holds a refreshed copy of the user per request; log in
        // again so the next request reads the stored value, not a stale token.
        $this->client->loginUser($this->em->find(User::class, $this->user->id));
    }

    private function rowButton(): string
    {
        return '[data-action~="click->mail--message-row#purge"]';
    }

    private function toolbarButton(string $method): string
    {
        return sprintf('[data-mail--list-toolbar-target="actions"] [data-action~="click->mail--list-toolbar#%s"]', $method);
    }

    /** One conversation of one message, carrying Spam and not the Inbox. */
    private function spamThread(string $subject): MessageThread
    {
        return $this->discardedThread($subject, $this->spam);
    }

    /** One conversation of one message, carrying Trash and not the Inbox. */
    private function binThread(string $subject): MessageThread
    {
        return $this->discardedThread($subject, $this->trash);
    }

    private function discardedThread(string $subject, Label $label): MessageThread
    {
        $thread = $this->thread($subject);

        $thread->removeLabel($this->inbox);
        $thread->addLabel($label);

        foreach ($thread->messages as $message) {
            $message->addLabel($label);
        }

        $this->em->flush();

        return $thread;
    }

    /** @param array<string, mixed> $body */
    private function postTo(string $url, array $body = []): void
    {
        // The `ajax` token, read the way the real caller reads it — from the
        // layout's meta tag. See BulkMoveGuardTest::post().
        $token = (string) $this->client->request('GET', '/mail/inbox')
            ->filter('meta[name="csrf-token"]')
            ->attr('content');

        $this->client->request(
            'POST',
            $url,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            (string) json_encode($body),
        );
    }
}
