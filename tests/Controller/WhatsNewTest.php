<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Interface\MailSenderInterface;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Mail\MailSenderRegistry;
use App\Service\System\AppVersion;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Mime\Email;

/**
 * What's new, end to end through the app (#165).
 *
 * The build is pinned to v0.3.5 of the real CHANGELOG.md, so these read the
 * notes that ship. The rules under test are the owner's: the dialog opens by
 * itself after an update and never on an install; "Don't show this after
 * updates" silences it, dot and all, until Settings → General turns it back
 * on; feedback is a mail from one of the person's own accounts.
 */
final class WhatsNewTest extends WebTestCase
{
    private const string BOOT = '[data-controller~="whats-new--autostart"]';
    private const string DOT  = '[data-whats-new-dot]';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;

    /** Every mail "sent" in a test, in place of the account's real transport. */
    private FeedbackOutbox $outbox;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();
        $container->set(AppVersion::class, new AppVersion('v0.3.5'));

        $this->outbox = new FeedbackOutbox();

        $container->set(MailSenderRegistry::class, new MailSenderRegistry([$this->outbox]));

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /** Somebody who was here before: the migration gave them 0.3.1. */
    public function testAfterAnUpdateTheDialogOpensByItselfAndTheQuestionMarkHasADot(): void
    {
        $this->signIn('0.3.1');

        $page = $this->page();

        self::assertCount(1, $page->filter(self::BOOT));
        self::assertCount(1, $page->filter(self::DOT));
    }

    /** A fresh install, or a user added since: nothing opens, and the first request says where they start. */
    public function testSomebodyNewIsToldNothing(): void
    {
        $user = $this->signIn(null);

        $page = $this->page();

        self::assertCount(0, $page->filter(self::BOOT));
        self::assertCount(0, $page->filter(self::DOT));
        self::assertSame('0.3.5', $this->reread($user)->whatsNewSeenVersion);
    }

    public function testTheDialogListsWhatIsNewAndRecordsItAsSeen(): void
    {
        $user   = $this->signIn('0.3.1');
        $dialog = $this->client->request('GET', '/whats-new');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['v0.3.5', 'v0.3.4', 'v0.3.3', 'v0.3.2'],
            $dialog->filter('nav [data-whats-new-release]')->slice(0, 4)->each(static fn (Crawler $a): string => (string) $a->attr('data-whats-new-release')),
        );
        self::assertSame('true', $dialog->filter('nav [data-whats-new-release="v0.3.5"]')->attr('aria-current'));
        self::assertSame('v0.3.5', $dialog->filter('[data-whats-new-notes]')->attr('data-whats-new-notes'));
        self::assertNull($dialog->filter('[data-whats-new-history]')->attr('open'), 'the full changelog starts folded');

        self::assertSame('0.3.5', $this->reread($user)->whatsNewSeenVersion);

        $page = $this->page();

        self::assertCount(0, $page->filter(self::BOOT), 'drawn once is seen');
        self::assertCount(0, $page->filter(self::DOT));
    }

    /** Clicking a release in "New to you" after the first draw keeps the list as it was. */
    public function testPickingAReleaseKeepsWhatWasNew(): void
    {
        $this->signIn('0.3.1');
        $this->client->request('GET', '/whats-new');

        $dialog = $this->client->request('GET', '/whats-new?release=v0.3.3&since=0.3.1');

        self::assertSame('v0.3.3', $dialog->filter('[data-whats-new-notes]')->attr('data-whats-new-notes'));
        self::assertSame('true', $dialog->filter('nav [data-whats-new-release="v0.3.3"]')->attr('aria-current'));
        self::assertCount(4, $dialog->filter('nav > div > [data-whats-new-release]'), 'still four new releases');
    }

    public function testAnOlderReleaseOpensTheFullChangelog(): void
    {
        $this->signIn('0.3.5');

        $dialog = $this->client->request('GET', '/whats-new?release=v0.3.0');

        self::assertSame('v0.3.0', $dialog->filter('[data-whats-new-notes]')->attr('data-whats-new-notes'));
        self::assertNotNull($dialog->filter('[data-whats-new-history]')->attr('open'));
    }

    public function testMigrationNotesAreForAdministratorsOnly(): void
    {
        $this->signIn('0.3.4');
        self::assertCount(0, $this->client->request('GET', '/whats-new?release=v0.3.5')->filter('[data-whats-new-admin]'));

        $this->signIn('0.3.4', ['ROLE_USER', 'ROLE_ADMIN']);
        self::assertCount(1, $this->client->request('GET', '/whats-new?release=v0.3.5')->filter('[data-whats-new-admin]'));
    }

    /** "Don't show this after updates" is silence, and Settings → General undoes it. */
    public function testQuietSilencesItUntilSettingsTurnsItBackOn(): void
    {
        $user   = $this->signIn('0.3.1');
        $dialog = $this->client->request('GET', '/whats-new');

        $this->client->submit($dialog->filter('form[action="/whats-new/quiet"]')->form(['quiet' => '1']));

        self::assertResponseIsSuccessful();
        self::assertTrue($this->reread($user)->whatsNewQuiet);

        // News that arrives while quiet does not announce itself.
        $this->reread($user)->whatsNewSeenVersion = '0.3.1';
        $this->em->flush();

        $page = $this->page();

        self::assertCount(0, $page->filter(self::BOOT));
        self::assertCount(0, $page->filter(self::DOT));
        self::assertCount(1, $page->filter('[data-whats-new-menu] [role="menuitem"]'), 'What\'s new is still in the menu');

        $settings = $this->client->request('GET', '/settings?section=general');

        self::assertSame('0', $settings->filter('form[action="/settings/whats-new"] input[name="show"]:checked')->attr('value'));

        $this->client->submit($settings->filter('form[action="/settings/whats-new"]')->form(['show' => '1']));

        self::assertResponseRedirects('/settings?section=general');
        self::assertFalse($this->reread($user)->whatsNewQuiet);
        self::assertCount(1, $this->page()->filter(self::DOT));
    }

    public function testFeedbackIsAMailFromTheChosenAccount(): void
    {
        $user    = $this->signIn('0.3.5');
        $account = $this->account($user, 'me@example.test');
        $form    = $this->client->request('GET', '/whats-new?pane=feedback')->filter('form[action="/whats-new/feedback"]')->form();

        $done = $this->client->submit($form, [
            'rating'  => 'better',
            'message' => 'Pull to sync is lovely.',
            'account' => (string) $account->id,
        ]);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $done->filter('[data-whats-new-feedback-sent]'));
        self::assertCount(1, $this->sent());

        $mail = $this->sent()[0];

        self::assertSame('feedback@pluetzner.de', $mail->getTo()[0]->getAddress());
        self::assertSame('me@example.test', $mail->getFrom()[0]->getAddress());
        self::assertSame('plMail v0.3.5 feedback: Better', $mail->getSubject());
        self::assertStringContainsString('Pull to sync is lovely.', (string) $mail->getTextBody());
        self::assertStringContainsString('plMail v0.3.5', (string) $mail->getTextBody());
    }

    public function testFeedbackCannotBeSentFromSomebodyElsesAccount(): void
    {
        $stranger = $this->user('stranger', '0.3.5');
        $theirs   = $this->account($stranger, 'them@example.test');

        $user = $this->signIn('0.3.5');
        $this->account($user, 'me@example.test');

        $form = $this->client->request('GET', '/whats-new?pane=feedback')->filter('form[action="/whats-new/feedback"]')->form();
        $field = $form->get('account');
        self::assertInstanceOf(ChoiceFormField::class, $field);
        $field->disableValidation()->setValue((string) $theirs->id);
        $form->setValues(['message' => 'Hello']);

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->sent());
    }

    public function testAnEmptyFeedbackIsSentBackWithTheFormAsTyped(): void
    {
        $user = $this->signIn('0.3.5');
        $this->account($user, 'me@example.test');

        $form = $this->client->request('GET', '/whats-new?pane=feedback')->filter('form[action="/whats-new/feedback"]')->form();
        $page = $this->client->submit($form, ['message' => '   ']);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $page->filter('form[action="/whats-new/feedback"] [role="alert"]:not([hidden])'));
        self::assertCount(0, $this->sent());
    }

    /** No account that can send, no feedback: the menu and the dialog leave it out. */
    public function testWithoutAnAccountThereIsNoFeedback(): void
    {
        $this->signIn('0.3.5');

        self::assertCount(1, $this->page()->filter('[data-whats-new-menu] [role="menuitem"]'));
        self::assertCount(0, $this->client->request('GET', '/whats-new?pane=feedback')->filter('form[action="/whats-new/feedback"]'));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** @return list<Email> */
    private function sent(): array
    {
        return $this->outbox->sent;
    }

    /**
     * @param list<string> $roles
     */
    private function signIn(?string $seen, array $roles = ['ROLE_USER']): User
    {
        $user = $this->user('whats-new', $seen, $roles);

        $this->client->loginUser($user);

        return $user;
    }

    /**
     * @param list<string> $roles
     */
    private function user(string $prefix, ?string $seen, array $roles = ['ROLE_USER']): User
    {
        $user            = new User();
        $user->email     = $prefix . '-' . uniqid('', true) . '@plmail.test';
        $user->nameFirst = 'Whats';
        $user->nameLast  = 'New';
        $user->roles     = $roles;
        $user->password  = 'x';

        $user->whatsNewSeenVersion = $seen;

        // Past setup: the dialog never opens over the setup wizard, and that is
        // WhatsNewGlobal's rule to test, not every test's to trip over.
        $user->setSetting(User::SETTING_ONBOARDING_COMPLETED_AT, '2026-10-01T00:00:00+00:00');

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function account(User $user, string $address): Account
    {
        $account                   = new Account();
        $account->usr              = $user;
        $account->name             = 'Feedback';
        $account->email            = $address;
        $account->username         = $address;
        $account->imapHost         = 'imap.example.test';
        $account->imapPort         = 993;
        $account->imapEncryption   = 'ssl';
        $account->authType         = AuthType::OAuth2->value;
        $account->oauthProvider    = 'microsoft';
        $account->oauthAccessToken = 'test-access-token';
        $account->oauthTokenExpiry = new DateTimeImmutable('+1 day');
        $account->isActive         = true;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    /** A page drawn in the app's layout, top bar and all. */
    private function page(): Crawler
    {
        $page = $this->client->request('GET', '/settings?section=general');

        self::assertResponseIsSuccessful();

        return $page;
    }

    private function reread(User $user): User
    {
        $this->em->clear();

        $fresh = $this->em->find(User::class, $user->id);
        self::assertInstanceOf(User::class, $fresh);

        return $fresh;
    }
}

/** Stands in for the account's transport: keeps what it is given, and says it filed the Sent copy. */
final class FeedbackOutbox implements MailSenderInterface
{
    /** @var list<Email> */
    public array $sent = [];

    public function supports(Account $account): bool
    {
        return true;
    }

    public function send(Email $email, Account $account): bool
    {
        $this->sent[] = $email;

        return true;
    }

    public function filesSentCopy(): bool
    {
        return true;
    }
}
