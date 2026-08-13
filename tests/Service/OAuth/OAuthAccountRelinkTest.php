<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Domain\Exception\AccountIdentityMismatch;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use App\Service\OAuth\OAuthAccountLinker;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Token\AccessToken;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Reconnecting an account in place — the repair this whole feature is for.
 *
 * WHAT THIS IS FOR
 * ────────────────
 * When an OAuth grant dies, the only remedy plMail offered was to delete the
 * account and add it back, which throws away every message, thread, label
 * assignment, rule and calendar hanging off that account id. People do it
 * anyway, because nothing tells them there is another way. relink() is the
 * other way, and these tests are the promise the settings page makes on its
 * behalf: your mail is still there afterwards.
 *
 * THE TEST THAT MATTERS MOST is testReconnectingAsADifferentMailboxIsRefused.
 * A user with two Google accounts, signed into the wrong one in their browser,
 * sails through the consent screen without noticing — and the tokens that come
 * back are perfectly valid, for somebody else's mail. Writing them onto this
 * row would make every later sync file a stranger's messages into these
 * threads, and there is no undo for that short of a restore. So the address is
 * compared before anything is written, and a mismatch is refused rather than
 * resolved cleverly.
 */
final class OAuthAccountRelinkTest extends KernelTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private EntityManagerInterface $em;
    private Connection $connection;
    private OAuthAccountLinker $linker;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->linker     = $container->get(OAuthAccountLinker::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->connection->beginTransaction();
        $this->user = $user;
    }

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The promise the button makes, asserted on the things that would actually
     * be lost by a delete-and-re-add: the row identity itself, and everything
     * keyed to it.
     */
    public function testReconnectingKeepsTheAccountRowAndEverythingOnIt(): void
    {
        $account = $this->deadAccount('keeper@joder.dev');

        $label  = $this->label('Receipts');
        $thread = $this->thread($account, 'An invoice', $label);

        $accountId = $account->id;
        $threadId  = $thread->id;

        $this->linker->relink($account, MailProvider::Google, 'keeper@joder.dev', $this->token('fresh-access'));

        $this->em->clear();

        $reloaded = $this->em->find(Account::class, $accountId);

        // The SAME row. Not a new one beside it, which is what link() would
        // have produced if the address had differed by so much as a case.
        self::assertNotNull($reloaded);
        self::assertSame($accountId, $reloaded->id);
        self::assertSame('keeper@joder.dev', $reloaded->email);

        // The mail is still hanging off it.
        $keptThread = $this->em->find(MessageThread::class, $threadId);

        self::assertNotNull($keptThread, 'the thread survived the reconnect');
        self::assertSame($accountId, $keptThread->account->id);
        self::assertCount(1, $keptThread->messages, 'and so did its message');
        self::assertSame('An invoice', $keptThread->subject);

        // Including the label assignment, which lives on the join table and is
        // the first casualty of deleting the account.
        self::assertCount(1, $keptThread->labels);
        self::assertSame('Receipts', $keptThread->labels->first()->name);
    }

    /**
     * The new tokens really are written, and the stored failure really is
     * cleared — the two lines that turn the health page from a list of
     * complaints into something that goes away when fixed.
     *
     * Without the clear, the account would work again while still reporting
     * itself broken: OAuthTokenManager only clears the error on a successful
     * REFRESH, and a reconnect never goes through refresh().
     */
    public function testReconnectingWritesTheNewTokensAndClearsTheStoredFailure(): void
    {
        $account = $this->deadAccount('tokens@joder.dev');

        self::assertSame('invalid_grant', $account->oauthLastRefreshError);

        $this->linker->relink($account, MailProvider::Google, 'tokens@joder.dev', $this->token('fresh-access', 'fresh-refresh'));

        $this->em->clear();
        $reloaded = $this->em->find(Account::class, $account->id);

        self::assertSame('fresh-access', $reloaded->oauthAccessToken);
        self::assertSame('fresh-refresh', $reloaded->oauthRefreshToken);
        self::assertNull($reloaded->oauthLastRefreshError, 'the health page has to be able to go quiet');
        self::assertNotNull($reloaded->oauthLastRefreshAt);
    }

    /**
     * A provider that does not rotate refresh tokens omits it from the
     * response, and the stored one stays valid. Overwriting with null would
     * break every later refresh — which is the bug that would turn one
     * reconnect into a reconnect every hour.
     */
    public function testAResponseWithNoRefreshTokenKeepsTheStoredOne(): void
    {
        $account = $this->deadAccount('norotate@joder.dev');

        $this->linker->relink($account, MailProvider::Google, 'norotate@joder.dev', $this->token('fresh-access'));

        $this->em->clear();
        $reloaded = $this->em->find(Account::class, $account->id);

        self::assertSame('stale-refresh', $reloaded->oauthRefreshToken);
    }

    /**
     * A grant that died while the account was switched off. Reconnecting is an
     * unambiguous "I want this working", so it comes back on.
     */
    public function testReconnectingReactivatesADisabledAccount(): void
    {
        $account           = $this->deadAccount('disabled@joder.dev');
        $account->isActive = false;
        $this->em->flush();

        $this->linker->relink($account, MailProvider::Google, 'disabled@joder.dev', $this->token('fresh-access'));

        $this->em->clear();

        self::assertTrue($this->em->find(Account::class, $account->id)->isActive);
    }

    // ── the identity guard ───────────────────────────────────────────────────

    /**
     * THE one that must never regress. Signing in as the wrong Google account
     * is a mistake anybody makes, and it must cost a refusal rather than a
     * mailbox.
     */
    public function testReconnectingAsADifferentMailboxIsRefused(): void
    {
        $account = $this->deadAccount('mine@joder.dev');
        $thread  = $this->thread($account, 'Still mine');

        $this->expectException(AccountIdentityMismatch::class);

        try {
            $this->linker->relink($account, MailProvider::Google, 'someone-else@joder.dev', $this->token('their-access', 'their-refresh'));
        } finally {
            // Nothing was written on the way to the refusal. The check runs
            // before the first assignment precisely so this holds.
            $this->em->clear();
            $reloaded = $this->em->find(Account::class, $account->id);

            self::assertSame('mine@joder.dev', $reloaded->email, 'the account still points at its own mailbox');
            self::assertSame('stale-access', $reloaded->oauthAccessToken, 'and carries none of the stranger\'s tokens');
            self::assertSame('stale-refresh', $reloaded->oauthRefreshToken);
            self::assertSame('invalid_grant', $reloaded->oauthLastRefreshError, 'still reported as broken');

            self::assertNotNull(
                $this->em->find(MessageThread::class, $thread->id),
                'and the mail that was never at risk is still there',
            );
        }
    }

    /**
     * Providers do not agree with themselves about capitalisation between the
     * id_token and the profile response. A refusal over `A` versus `a` would be
     * a wall with no way through it, so the comparison is case-insensitive.
     */
    public function testADifferentlyCapitalisedAddressIsTheSameMailbox(): void
    {
        $account = $this->deadAccount('casing@joder.dev');

        $this->linker->relink($account, MailProvider::Google, 'Casing@Joder.dev', $this->token('fresh-access'));

        $this->em->clear();

        self::assertNull($this->em->find(Account::class, $account->id)->oauthLastRefreshError);
    }

    /**
     * The same address can legitimately exist as both a Google and a Microsoft
     * account — see OAuthAccountLinker::upsert(). Re-pointing one at the other
     * is the same corruption by a slower route.
     */
    public function testReconnectingWithADifferentProviderIsRefused(): void
    {
        $account = $this->deadAccount('crossed@joder.dev');

        $this->expectException(AccountIdentityMismatch::class);

        $this->linker->relink($account, MailProvider::Microsoft, 'crossed@joder.dev', $this->token('fresh-access'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** An OAuth account in exactly the state the live install was found in. */
    private function deadAccount(string $email): Account
    {
        $account = new Account();

        $account->usr                   = $this->user;
        $account->name                  = $email;
        $account->username              = $email;
        $account->email                 = $email;
        $account->authType              = AuthType::OAuth2->value;
        $account->oauthProvider         = MailProvider::Google->value;
        $account->oauthAccessToken      = 'stale-access';
        $account->oauthRefreshToken     = 'stale-refresh';
        $account->oauthLastRefreshError = 'invalid_grant';
        $account->isActive              = true;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function label(string $name): Label
    {
        $label            = new Label();
        $label->usr       = $this->user;
        $label->name      = $name;
        $label->isVisible = true;

        $this->em->persist($label);
        $this->em->flush();

        return $label;
    }

    private function thread(Account $account, string $subject, ?Label $label = null): MessageThread
    {
        $thread                    = new MessageThread();
        $thread->account           = $account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower($subject);
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable();
        $thread->category          = MessageCategory::Primary;
        $thread->messageCount      = 1;
        $thread->unreadCount       = 0;

        if (null !== $label) {
            $thread->addLabel($label);
        }

        $this->em->persist($thread);

        $message                 = new Message();
        $message->account        = $account;
        $message->thread         = $thread;
        $message->subject        = $subject;
        $message->fromAddress    = 'sender@example.test';
        $message->receivedAt     = new DateTimeImmutable();
        $message->sentAt         = $message->receivedAt;
        $message->seenAt         = new DateTimeImmutable();
        $message->flags          = [];
        $message->hasAttachments = false;

        $thread->addMessage($message);
        $this->em->persist($message);
        $this->em->flush();

        return $thread;
    }

    private function token(string $access, ?string $refresh = null): AccessToken
    {
        $values = [
            'access_token' => $access,
            'expires'      => time() + 3600,
        ];

        if (null !== $refresh) {
            $values['refresh_token'] = $refresh;
        }

        return new AccessToken($values);
    }
}
