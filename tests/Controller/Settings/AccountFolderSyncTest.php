<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\User\User;
use App\Repository\Mail\MailboxRepository;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Choosing which of an IMAP account's folders plMail fetches.
 *
 * Every claim is a way this could be wrong rather than merely absent:
 *
 * - the owner can switch a folder off and on again, and the answer is the
 *   folder's own row so the page does not move;
 * - the list is this account's folders and nobody else's;
 * - another person's account is refused, however its id was found;
 * - a folder that belongs to a DIFFERENT account of the same person cannot be
 *   reached through this one — the id in the path is a pair, and a pair has to
 *   agree with itself;
 * - a POST without a valid CSRF token changes nothing;
 * - the inbox cannot be switched off, because a client that stops fetching its
 *   inbox is not a client;
 * - accounts that sync over a provider API have no IMAP folders to choose, so
 *   neither the list nor the toggle exists for them.
 *
 * Skips itself without the seeded users, as the other controller tests do.
 */
final class AccountFolderSyncTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';
    private const string OTHER_EMAIL = 'e2e@plmail.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private MailboxRepository $mailboxes;
    private User $user;
    private Account $account;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // The rows below are staged inside a rolled-back transaction, so the
        // kernel must not reboot between requests — a rebooted kernel opens a
        // fresh connection that cannot see them.
        $this->client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->mailboxes  = $container->get(MailboxRepository::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (false === $user instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->user = $user;
        $this->client->loginUser($user);

        $this->connection->beginTransaction();
        $this->account = $this->imapAccount($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheOwnerSwitchesAFolderOffAndOnAgain(): void
    {
        $drafts = $this->mailbox($this->account, 'Drafts');
        $id     = (int) $drafts->id;

        $this->post($this->account, $id, $this->csrfToken($id));

        self::assertResponseIsSuccessful();
        // The answer is the folder's own frame, so the row changes in place.
        self::assertSelectorExists('turbo-frame#account-folder-sync-' . $id);
        self::assertStringContainsString('Start syncing Drafts', (string) $this->client->getResponse()->getContent());
        self::assertFalse($this->flag($id), 'the folder is switched off');

        $this->post($this->account, $id, $this->csrfToken($id));

        self::assertStringContainsString('Stop syncing Drafts', (string) $this->client->getResponse()->getContent());
        self::assertTrue($this->flag($id), 'and back on');
    }

    public function testTheListIsThisAccountsFoldersAndTheInboxCannotBeSwitched(): void
    {
        $this->mailbox($this->account, 'Receipts');
        $this->mailbox($this->account, 'INBOX', MailboxSpecialUse::INBOX);

        $second = $this->imapAccount($this->user);
        $this->mailbox($second, 'Only On The Second Account');

        $crawler = $this->client->request('GET', $this->foldersUrl($this->account));

        self::assertResponseIsSuccessful();

        $text = $crawler->filter('body')->text();

        self::assertStringContainsString('Receipts', $text);
        self::assertStringNotContainsString('Only On The Second Account', $text);

        // The inbox is listed, with its control disabled and no form to post
        // to; every other folder's control is live.
        $inboxId = (int) $this->mailboxes->findOneBy(['account' => $this->account, 'fullPath' => 'INBOX'])?->id;

        self::assertSelectorExists('turbo-frame#account-folder-sync-' . $inboxId . ' button[disabled]');

        $receiptsId = (int) $this->mailboxes->findOneBy(['account' => $this->account, 'fullPath' => 'Receipts'])?->id;

        self::assertSelectorExists('turbo-frame#account-folder-sync-' . $receiptsId . ' button:not([disabled])');

        // And the switch keeps the modal open. The modal closes on every
        // successful submit unless the form opts out, and a list you have to
        // reopen after each switch is not a list you can work down.
        self::assertSelectorExists('turbo-frame#account-folder-sync-' . $receiptsId . ' form[data-ui--modal-keep-open]');
    }

    /**
     * Nothing polls a folder the server has stopped listing, and it is on its
     * way to being removed, so a switch beside it would be wired to nothing.
     */
    public function testAFolderTheServerNoLongerListsIsNotOffered(): void
    {
        $this->mailbox($this->account, 'StillThere');

        $gone = $this->mailbox($this->account, 'Vanished');
        $gone->missingSince = new \DateTimeImmutable();
        $this->em->flush();

        $text = $this->client->request('GET', $this->foldersUrl($this->account))->filter('body')->text();

        self::assertStringContainsString('StillThere', $text);
        self::assertStringNotContainsString('Vanished', $text);
    }

    /**
     * "Archive" sorts before "INBOX" by path, so the inbox leading the list is
     * the ordering's doing and not an accident of the alphabet.
     */
    public function testTheInboxComesFirstAndTheRestFollowByPath(): void
    {
        $this->mailbox($this->account, 'Archive');
        $this->mailbox($this->account, 'Zebra');
        $this->mailbox($this->account, 'INBOX', MailboxSpecialUse::INBOX);

        $text = $this->client->request('GET', $this->foldersUrl($this->account))->filter('body')->text();

        $inbox   = strpos($text, 'INBOX');
        $archive = strpos($text, 'Archive');
        $zebra   = strpos($text, 'Zebra');

        self::assertNotFalse($inbox);
        self::assertNotFalse($archive);
        self::assertNotFalse($zebra);
        self::assertLessThan($archive, $inbox);
        self::assertLessThan($zebra, $archive);
    }

    public function testAnotherPersonsAccountIsRefused(): void
    {
        $other = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::OTHER_EMAIL]);

        if (false === $other instanceof User) {
            self::markTestSkipped('run `app:test:seed-user` first');
        }

        $theirs = $this->imapAccount($other);
        $folder = $this->mailbox($theirs, 'Drafts');
        $id     = (int) $folder->id;

        $this->client->request('GET', $this->foldersUrl($theirs));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->post($theirs, $id, $this->csrfToken($id));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->flag($id), 'their folder is untouched');
    }

    /**
     * The path carries two ids, and both are the caller's to choose. The
     * account is checked for ownership, so the folder has to be checked
     * against THAT account — or a person could reach any folder id by
     * pairing it with an account of their own.
     */
    public function testAFolderOfAnotherAccountCannotBeReachedThroughThisOne(): void
    {
        $second = $this->imapAccount($this->user);
        $folder = $this->mailbox($second, 'Drafts');
        $id     = (int) $folder->id;

        $this->post($this->account, $id, $this->csrfToken($id));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->flag($id), 'the folder is untouched');

        // Presence: through its own account the very same id works.
        $this->post($second, $id, $this->csrfToken($id));
        self::assertResponseIsSuccessful();
        self::assertFalse($this->flag($id));
    }

    public function testAPostWithoutAValidCsrfTokenIsRefused(): void
    {
        $drafts = $this->mailbox($this->account, 'Drafts');
        $id     = (int) $drafts->id;

        $this->post($this->account, $id, 'not-the-token');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->flag($id), 'nothing changed');
    }

    public function testTheInboxCannotBeSwitchedOffByHand(): void
    {
        $inbox = $this->mailbox($this->account, 'INBOX', MailboxSpecialUse::INBOX);
        $id    = (int) $inbox->id;

        $this->post($this->account, $id, $this->csrfToken($id));

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->flag($id), 'the inbox is still synced');
    }

    /**
     * A folder the server will not open — \\Noselect, a placeholder that only
     * groups other folders — holds nothing to fetch. It was offered a switch
     * like any other, and switching it on lasted until the next folder sync,
     * which turns such a folder off again: a control that did not stick.
     */
    public function testAPlaceholderFolderIsListedGreyedOutAndCannotBeSwitchedOn(): void
    {
        $placeholder = $this->mailbox($this->account, 'Projects');
        $placeholder->isSelectable  = false;
        $placeholder->isSyncEnabled = false;
        $this->em->flush();

        $ordinary = $this->mailbox($this->account, 'Receipts');

        $this->client->request('GET', $this->foldersUrl($this->account));

        self::assertResponseIsSuccessful();

        $frame = sprintf('turbo-frame#account-folder-sync-%d', (int) $placeholder->id);

        self::assertSelectorExists(sprintf('%s button[disabled]', $frame));
        self::assertSelectorNotExists(sprintf('%s form', $frame));
        self::assertSelectorExists(sprintf('%s [data-folder-placeholder]', $frame));

        // Presence: an ordinary folder beside it is neither.
        $other = sprintf('turbo-frame#account-folder-sync-%d', (int) $ordinary->id);

        self::assertSelectorExists(sprintf('%s form', $other));
        self::assertSelectorNotExists(sprintf('%s [data-folder-placeholder]', $other));

        // And the endpoint agrees with the page, for whoever posts without it.
        $id = (int) $placeholder->id;
        $this->post($this->account, $id, $this->csrfToken($id));

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->flag($id), 'still off');
    }

    /**
     * A row prints the last segment of the path, so "Projects/Alpha" reads
     * "Alpha" — filed under P among its neighbours with nothing to say why,
     * and indistinguishable from an "Alpha" under anything else.
     */
    public function testANestedFolderIsIndentedUnderItsParentRatherThanFiledByItsLastName(): void
    {
        $this->mailbox($this->account, 'Projects');
        $child      = $this->mailbox($this->account, 'Alpha', null, 'Projects/Alpha');
        $grandchild = $this->mailbox($this->account, '2026', null, 'Projects/Alpha/2026');
        $top        = $this->mailbox($this->account, 'Receipts');

        $crawler = $this->client->request('GET', $this->foldersUrl($this->account));

        self::assertResponseIsSuccessful();

        $depth = static fn (Mailbox $mailbox): string => (string) $crawler
            ->filter(sprintf('turbo-frame#account-folder-sync-%d', (int) $mailbox->id))
            ->attr('data-folder-depth');

        self::assertSame('0', $depth($top));
        self::assertSame('1', $depth($child));
        self::assertSame('2', $depth($grandchild));

        $padding = static fn (Mailbox $mailbox): int => (int) preg_replace('/\\D+/', '', (string) $crawler
            ->filter(sprintf('turbo-frame#account-folder-sync-%d', (int) $mailbox->id))
            ->attr('style'));

        self::assertGreaterThan($padding($top), $padding($child));
        self::assertGreaterThan($padding($child), $padding($grandchild));
    }

    public function testAnApiAccountHasNoFolderListToChooseFrom(): void
    {
        $gmail = $this->imapAccount($this->user);
        $gmail->authType      = 'oauth2';
        $gmail->oauthProvider = 'google';
        $this->em->flush();

        $folder = $this->mailbox($gmail, 'Drafts');
        $id     = (int) $folder->id;

        $this->client->request('GET', $this->foldersUrl($gmail));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->post($gmail, $id, $this->csrfToken($id));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->flag($id));
    }

    public function testTheAccountsListOffersTheFolderListOnlyWhereThereIsOne(): void
    {
        $gmail = $this->imapAccount($this->user);
        $gmail->authType      = 'oauth2';
        $gmail->oauthProvider = 'google';
        $this->em->flush();

        $crawler = $this->client->request('GET', '/settings?section=accounts');

        self::assertResponseIsSuccessful();

        $html = $crawler->html();

        self::assertStringContainsString($this->foldersUrl($this->account), $html);
        self::assertStringNotContainsString($this->foldersUrl($gmail), $html);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function foldersUrl(Account $account): string
    {
        return '/settings/accounts/' . $account->id . '/folders';
    }

    private function post(Account $account, int $mailboxId, string $token): void
    {
        $this->client->request(
            'POST',
            $this->foldersUrl($account) . '/' . $mailboxId . '/sync',
            ['_token' => $token],
        );
    }

    /**
     * Read back from the database, not from the identity map the request just
     * wrote through.
     */
    private function flag(int $mailboxId): bool
    {
        $this->em->clear();

        $mailbox = $this->mailboxes->find($mailboxId);

        self::assertNotNull($mailbox);

        return true === $mailbox->isSyncEnabled;
    }

    /**
     * The token manager reads the session off the current request, and there
     * is no current request between two client calls — so the page the form
     * lives on is fetched and its session pushed onto the stack, exactly as
     * PushDeviceRemovalTest does it.
     */
    private function csrfToken(int $mailboxId): string
    {
        $this->client->request('GET', '/settings?section=accounts');

        $stack   = static::getContainer()->get('request_stack');
        $carrier = new Request();
        $carrier->setSession($this->client->getRequest()->getSession());
        $stack->push($carrier);

        try {
            return static::getContainer()
                ->get('security.csrf.token_manager')
                ->getToken('account_folder_sync_' . $mailboxId)
                ->getValue();
        } finally {
            $this->client->getRequest()->getSession()->save();
            $stack->pop();
        }
    }

    private function imapAccount(User $owner): Account
    {
        $account = new Account();
        $account->usr            = $owner;
        $account->email          = 'folders-' . uniqid('', true) . '@example.test';
        $account->username       = $account->email;
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

    private function mailbox(Account $account, string $name, ?MailboxSpecialUse $specialUse = null, ?string $fullPath = null): Mailbox
    {
        $mailbox = new Mailbox();
        $mailbox->account       = $account;
        $mailbox->name          = $name;
        $mailbox->fullPath      = $fullPath ?? $name;
        $mailbox->delimiter     = '/';
        $mailbox->specialUse    = $specialUse;
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;
        $this->em->persist($mailbox);
        $this->em->flush();

        return $mailbox;
    }
}
