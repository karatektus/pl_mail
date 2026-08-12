<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Domain\Enum\Mail\MessagePriority;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The last three compose-window controls, from the browser's side.
 *
 * Signature, the more-options menu and the encrypt button. What is worth
 * pinning here rather than in a unit test is the wiring, because each of these
 * has a way of being present and inert:
 *
 *  - the priority and receipt fields are MAPPED on ComposeType, so until the
 *    menu renders them every autosave binds them to null and false. Rendering
 *    them is the feature; proving they bind is proving the rendering;
 *  - the signature has to reach the window as data the From switch can use, or
 *    changing sender silently keeps the old sign-off;
 *  - the encrypt button has to be disabled AND named, because a live-looking
 *    lock icon on an unencrypted message is the one lie a mail client cannot
 *    tell.
 */
final class ComposeSignatureAndOptionsTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private EntityManagerInterface $em;
    private Connection $connection;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── the window ───────────────────────────────────────────────────────────

    public function testANewDraftOpensWithTheAccountSignatureAlreadyInTheBody(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'signed@joder.dev');

        $account->setSetting(Account::SETTING_SIGNATURE, '<p>Ada Lovelace</p>');
        $this->em->flush();

        $crawler = $client->request('GET', '/compose/new');

        self::assertResponseIsSuccessful();
        self::assertCount(
            1,
            $crawler->filter('[data-compose--compose-target="body"] [data-pl-signature]'),
            'the editor opens signed',
        );
    }

    /**
     * The From switch is a DOM swap, not a fetch, so the signatures have to
     * arrive with the window — keyed the way the account <select> carries them.
     */
    public function testTheWindowCarriesEveryFromOptionsSignature(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'work@joder.dev');
        $alias   = $this->alias($account, 'personal@joder.dev');

        $account->setSetting(Account::SETTING_SIGNATURE, '<p>Ada, Acme Ltd</p>');
        $account->setSetting(Account::signatureAliasSetting((int) $alias->id), '<p>ada</p>');
        $this->em->flush();

        $crawler = $client->request('GET', '/compose/new');
        $raw     = $crawler->filter('[data-compose--compose-signatures-value]')
            ->attr('data-compose--compose-signatures-value');

        $map = json_decode((string) $raw, true);

        self::assertIsArray($map);
        self::assertStringContainsString(
            'ada',
            (string) ($map[$account->id . '|personal@joder.dev'] ?? ''),
            'the alias signs with its own',
        );
        self::assertStringContainsString(
            'Acme Ltd',
            (string) ($map[$account->id . '|work@joder.dev'] ?? ''),
            'and the account address with the account signature',
        );
    }

    public function testTheEncryptButtonIsDisabledAndSaysWhy(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);

        $this->account($user, 'encrypt@joder.dev');
        $this->em->flush();

        $crawler = $client->request('GET', '/compose/new');
        $button  = $crawler->filter('button[disabled] .fa-lock')->closest('button');

        self::assertNotNull($button, 'the encrypt button is present and disabled');
        self::assertNotSame('', (string) $button->attr('aria-label'), 'and still named');

        self::assertStringContainsString(
            'not available yet',
            $crawler->filter('[title]')->reduce(
                static fn ($node): bool => str_contains((string) $node->attr('title'), 'not available'),
            )->attr('title'),
        );
    }

    public function testTheMoreOptionsMenuRendersBothMappedFields(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);

        $this->account($user, 'options@joder.dev');
        $this->em->flush();

        $crawler = $client->request('GET', '/compose/new');

        self::assertCount(1, $crawler->filter('select[name="compose[priority]"]'));
        self::assertCount(1, $crawler->filter('input[name="compose[readReceiptRequested]"]'));
        self::assertCount(
            1,
            $crawler->filter('[data-compose--compose-target="plainBody"]'),
            'and the plain-text surface the menu toggles',
        );
    }

    // ── binding ──────────────────────────────────────────────────────────────

    /**
     * The whole point of rendering the fields: an autosave carries them.
     */
    public function testPriorityAndReceiptBindThroughTheAutosave(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'binds@joder.dev');

        $this->post($client, '/compose/draft', $account, [
            'bodyHtml'             => '<p>Urgent-ish.</p>',
            'priority'             => 'high',
            'readReceiptRequested' => '1',
        ]);

        self::assertResponseIsSuccessful();

        $draft = $this->lastDraft($account);

        self::assertSame(MessagePriority::High, $draft->priority);
        self::assertTrue($draft->readReceiptRequested);
    }

    /**
     * Omitted is "no opinion", not Normal — the difference MessagePriority's
     * docblock exists for, and the one that decides whether headers go out.
     */
    public function testAnOmittedPriorityIsNoOpinionRatherThanNormal(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'noopinion@joder.dev');

        $this->post($client, '/compose/draft', $account, ['bodyHtml' => '<p>Ordinary.</p>']);

        $draft = $this->lastDraft($account);

        self::assertNull($draft->priority);
        self::assertFalse($draft->readReceiptRequested);
    }

    /**
     * Plain-text mode on the wire: the window posts an empty bodyHtml and the
     * text it actually holds. What must NOT happen is DraftPersister deriving
     * the text part from the (empty) HTML and wiping the message.
     */
    public function testAPlainTextDraftKeepsItsTextAndStoresNoHtml(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'plain@joder.dev');

        $this->post($client, '/compose/draft', $account, [
            'bodyHtml' => '',
            'bodyText' => "Just words.\nNo markup.",
        ]);

        $draft = $this->lastDraft($account);

        self::assertSame("Just words.\nNo markup.", $draft->bodyText);
        self::assertTrue(null === $draft->bodyHtml || '' === $draft->bodyHtml);
    }

    /**
     * ...and reopening it puts the window back in plain-text mode, which is why
     * the mode needs no column: a message with text and no HTML IS a
     * plain-text message.
     */
    public function testReopeningAPlainTextDraftShowsThePlainSurface(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'reopen@joder.dev');

        $this->post($client, '/compose/draft', $account, [
            'bodyHtml' => '',
            'bodyText' => 'Just words.',
        ]);

        $draft   = $this->lastDraft($account);
        $crawler = $client->request('GET', '/compose/edit/' . $draft->id);

        self::assertResponseIsSuccessful();

        $textarea = $crawler->filter('[data-compose--compose-target="plainBody"]');

        self::assertNull($textarea->attr('disabled'), 'the plain surface is live, so it is submitted');
        self::assertStringContainsString('Just words.', $textarea->text());
    }

    /**
     * Rich mode is unchanged: emptying the editor still clears the text part
     * rather than leaving the last version of it behind.
     */
    public function testEmptyingARichDraftStillClearsTheTextPart(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'clears@joder.dev');

        $this->post($client, '/compose/draft', $account, ['bodyHtml' => '<p>Something.</p>']);

        $draft = $this->lastDraft($account);

        self::assertSame('Something.', $draft->bodyText);

        $this->post($client, '/compose/draft/' . $draft->id, $account, ['bodyHtml' => '']);

        $this->em->clear();

        self::assertNull($this->em->find(Message::class, $draft->id)->bodyText);
    }

    // ── the settings panel ───────────────────────────────────────────────────

    public function testSavingAnAccountSignatureSanitisesIt(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'settings@joder.dev');

        $this->em->flush();

        $url = '/account/' . $account->id . '/compose-defaults/signature';

        $client->request('POST', $url, [
            '_token'    => $this->csrf($client, $url),
            'signature' => '<p>Ada</p><script>alert(1)</script>',
        ]);

        // A plain POST, so the controller's redirect fallback answers rather
        // than the Turbo Stream — the panel works without JavaScript too.
        self::assertResponseRedirects('/settings');

        $stored = (string) $this->reload($account)->getSetting(Account::SETTING_SIGNATURE);

        self::assertStringNotContainsString('script', $stored);
        self::assertStringContainsString('Ada', $stored);
    }

    /**
     * The three-state alias control. Storing an empty signature and inheriting
     * are DIFFERENT, and the key's presence is what says which.
     */
    public function testAnAliasCanSignWithNothingOrInheritAndTheTwoAreDistinct(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'aliases@joder.dev');
        $alias   = $this->alias($account, 'other@joder.dev');

        $account->setSetting(Account::SETTING_SIGNATURE, '<p>Account wide</p>');
        $this->em->flush();

        $key = Account::signatureAliasSetting((int) $alias->id);
        $url = '/account/' . $account->id . '/compose-defaults/' . $alias->id . '/signature';

        // Say nothing, deliberately: the key is stored, empty.
        $token = $this->csrf($client, $url);

        $client->request('POST', $url, [
            '_token'    => $token,
            'signature' => '',
        ]);

        self::assertResponseRedirects('/settings');
        self::assertSame('', $this->reload($account)->getSetting($key));

        // ...and inherit removes it again, which is not the same value.
        $client->request('POST', $url, [
            '_token'  => $token,
            'inherit' => '1',
        ]);

        self::assertResponseRedirects('/settings');
        self::assertNull($this->reload($account)->getSetting($key));
    }

    public function testSavingASignatureWithoutACsrfTokenIsRefused(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'nocsrf@joder.dev');

        $this->em->flush();

        $client->request('POST', '/account/' . $account->id . '/compose-defaults/signature', [
            'signature' => '<p>Nope</p>',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $fields
     */
    private function post(KernelBrowser $client, string $url, Account $account, array $fields): void
    {
        $crawler = $client->request('GET', '/compose/new');
        $token   = $crawler->filter('input[name="compose[_token]"]')->attr('value');

        $client->request('POST', $url, ['compose' => $fields + [
            '_token'      => $token,
            'account'     => $account->id . '|' . $account->email,
            'toAddresses' => ['rike@example.test'],
            'subject'     => 'Options',
        ]]);
    }

    /**
     * The token off the rendered panel, not one minted out of band.
     *
     * A token asked of the manager directly has no session behind it in a
     * functional test, and reading the real one also proves the panel actually
     * draws the control being posted to.
     */
    private function csrf(KernelBrowser $client, string $formAction): string
    {
        $crawler = $client->request('GET', '/settings?section=aliases');

        self::assertResponseIsSuccessful();

        $form = $crawler->filter(sprintf('form[action="%s"]', $formAction));

        self::assertGreaterThan(0, $form->count(), sprintf('the panel renders a form posting to %s', $formAction));

        return (string) $form->filter('input[name="_token"]')->attr('value');
    }

    private function reload(Account $account): Account
    {
        $this->em->clear();

        return $this->em->find(Account::class, $account->id);
    }

    private function lastDraft(Account $account): Message
    {
        $this->em->clear();

        $message = $this->em->createQuery(
            'SELECT m FROM ' . Message::class . ' m WHERE m.account = :account ORDER BY m.id DESC',
        )->setParameter('account', $account->id)->setMaxResults(1)->getOneOrNullResult();

        self::assertNotNull($message, 'the POST saved a draft');

        return $message;
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    private function boot(KernelBrowser $client): User
    {
        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

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

    private function alias(Account $account, string $address): EmailAlias
    {
        $alias = new EmailAlias($account, $address, EmailAliasSource::Manual, EmailAliasStatus::Active);

        $account->addAlias($alias);
        $this->em->persist($alias);
        $this->em->flush();

        return $alias;
    }
}
