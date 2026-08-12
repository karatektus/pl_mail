<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The Signatures settings section.
 *
 * Split out of ComposeSignatureAndOptionsTest along with the panel itself,
 * which now has its own section and its own controller.
 *
 * WHAT THE OLD SUITE MISSED, AND WHY
 * ──────────────────────────────────
 * Users reported that an inheriting alias's editor looked live until the
 * inherit box was toggled, and the suite was green throughout. It was green
 * because it only ever asserted `contenteditable="false"` — which the markup
 * DID set correctly on first render. The greying was applied by a separate
 * mechanism (a `data-signature-disabled` attribute that matched no CSS rule,
 * plus an `opacity-50` class added only by the checkbox's change handler), and
 * nothing asserted the two agreed. An assertion on the attribute could not
 * fail for a bug that lived in the appearance.
 *
 * The gap is closed by not having a disabled state to get wrong: an inheriting
 * address renders NO editor, and testAnInheritingAliasRendersNoEditor asserts
 * an ABSENCE. Absence is a property of the rendered document, so no second
 * mechanism can silently disagree with it.
 */
final class SignatureSectionTest extends WebTestCase
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

    // ── the section ──────────────────────────────────────────────────────────

    public function testTheSectionRendersAndIsReachableFromTheNav(): void
    {
        $client = static::createClient();
        $user   = $this->boot($client);

        $this->account($user, 'nav@joder.dev');
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=accounts');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $crawler->filter('nav a[href*="section=signature"]')->count(),
            'the settings nav links to the signature section',
        );

        $crawler = $client->request('GET', '/settings?section=signature');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('#settings-signature')->count());
        self::assertSelectorTextContains('h2', 'Signatures');

        // The section survives SettingsController::SECTIONS — an unknown one
        // falls back to accounts, which would render no panel at all.
        self::assertGreaterThan(
            0,
            $crawler->filter('nav a[href*="section=signature"][aria-current="page"]')->count(),
            'the nav marks the signature section as the current page',
        );
    }

    // ── "gone, not greyed" ───────────────────────────────────────────────────

    /**
     * The bug the overhaul was for: an alias with no signature of its own gets
     * ONE LINE, and the editor is not in the document.
     *
     * Asserted as an absence deliberately — see the class docblock.
     */
    public function testAnInheritingAliasRendersNoEditor(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'inherits@joder.dev');
        $alias   = $this->alias($account, 'quiet@joder.dev');

        $account->setSetting(Account::SETTING_SIGNATURE, '<p>Account wide</p>');
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=signature');

        self::assertResponseIsSuccessful();

        $row = $crawler->filter(sprintf('[data-signature-alias="%d"]', $alias->id));

        self::assertSame(1, $row->count(), 'the alias has a row');
        self::assertSame('inherits', $row->attr('data-signature-state'));
        self::assertSame(0, $row->filter('[contenteditable]')->count(), 'and no editor of any kind in it');
        self::assertSame(0, $row->filter('[data-signature-disabled]')->count());
        self::assertSame(0, $row->filter('form[data-controller="compose--compose-toolbar"]')->count());

        // Exactly one editor on the page: the account's.
        self::assertSame(
            1,
            $crawler->filter('#settings-signature [contenteditable="true"]')->count(),
            'one account editor and nothing else',
        );

        // And nothing anywhere claims to be a disabled editor.
        self::assertSame(0, $crawler->filter('[contenteditable="false"]')->count());
    }

    /**
     * Overriding, then reverting, and the row shape follows the state both ways
     * without the user having to toggle anything twice.
     */
    public function testOverridingThenRevertingRestoresInheritance(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'roundtrip@joder.dev');
        $alias   = $this->alias($account, 'own@joder.dev');

        $account->setSetting(Account::SETTING_SIGNATURE, '<p>Account wide</p>');
        $this->em->flush();

        $key = Account::signatureAliasSetting((int) $alias->id);
        $url = '/account/' . $account->id . '/signature/' . $alias->id;

        self::assertNull($this->reload($account)->getSetting($key));

        // "Use a different one" — seeded from the account signature, so the
        // mail this address sends does not change the moment you press it.
        $token = $this->aliasToken($client, $alias->id);

        $client->request('POST', $url, ['_token' => $token, 'override' => '1']);
        self::assertResponseRedirects('/settings?section=signature');

        $stored = $this->reload($account)->getSetting($key);

        self::assertIsString($stored);
        self::assertStringContainsString('Account wide', $stored);

        // ...and now the row IS an editor, on first render, no toggling.
        $crawler = $client->request('GET', '/settings?section=signature');
        $row     = $crawler->filter(sprintf('[data-signature-alias="%d"]', $alias->id));

        self::assertSame('overrides', $row->attr('data-signature-state'));
        self::assertSame(1, $row->filter('[contenteditable="true"]')->count());
        self::assertSame(0, $row->filter('[contenteditable="false"]')->count());

        // "Use the account signature" — the key goes away again.
        $client->request('POST', $url, ['_token' => $token, 'inherit' => '1']);
        self::assertResponseRedirects('/settings?section=signature');
        self::assertNull($this->reload($account)->getSetting($key));

        $crawler = $client->request('GET', '/settings?section=signature');
        $row     = $crawler->filter(sprintf('[data-signature-alias="%d"]', $alias->id));

        self::assertSame('inherits', $row->attr('data-signature-state'));
        self::assertSame(0, $row->filter('[contenteditable]')->count());
    }

    /**
     * Pressing "use a different one" on an address that already has one must
     * not overwrite it with the account's copy — a stale panel can post it.
     */
    public function testOverridingAnAddressThatAlreadyOverridesKeepsItsSignature(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'stale@joder.dev');
        $alias   = $this->alias($account, 'kept@joder.dev');

        $account->setSetting(Account::SETTING_SIGNATURE, '<p>Account wide</p>');
        $account->setSetting(Account::signatureAliasSetting((int) $alias->id), '<p>Mine</p>');
        $this->em->flush();

        $url = '/account/' . $account->id . '/signature/' . $alias->id;

        $client->request('POST', $url, [
            '_token'   => $this->aliasToken($client, $alias->id),
            'override' => '1',
        ]);

        self::assertResponseRedirects('/settings?section=signature');
        self::assertSame(
            '<p>Mine</p>',
            $this->reload($account)->getSetting(Account::signatureAliasSetting((int) $alias->id)),
        );
    }

    // ── the disclosure ───────────────────────────────────────────────────────

    /**
     * Closed when nothing is overridden, open when something is — the whole
     * point of the compact default is that it never hides configuration you
     * already made.
     */
    public function testTheDisclosureStartsOpenOnlyWhenAnAddressOverrides(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'disclosure@joder.dev');
        $alias   = $this->alias($account, 'one@joder.dev');

        $this->alias($account, 'two@joder.dev');
        $this->alias($account, 'three@joder.dev');
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=signature');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('#settings-signature details')->count());
        self::assertNull(
            $crawler->filter('#settings-signature details')->attr('open'),
            'no overrides, so the per-address list starts folded away',
        );

        $account->setSetting(Account::signatureAliasSetting((int) $alias->id), '<p>Mine</p>');
        $this->em->flush();

        $crawler = $client->request('GET', '/settings?section=signature');

        self::assertNotNull(
            $crawler->filter('#settings-signature details')->attr('open'),
            'one override, so it starts open',
        );
    }

    // ── the three states, through the UI ─────────────────────────────────────

    public function testSavingAnAccountSignatureSanitisesIt(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'settings@joder.dev');

        $this->em->flush();

        $url = '/account/' . $account->id . '/signature';

        $client->request('POST', $url, [
            '_token'    => $this->csrf($client, $url),
            'signature' => '<p>Ada</p><script>alert(1)</script>',
        ]);

        // A plain POST, so the controller's redirect fallback answers rather
        // than the Turbo Stream — the panel works without JavaScript too.
        self::assertResponseRedirects('/settings?section=signature');

        $stored = (string) $this->reload($account)->getSetting(Account::SETTING_SIGNATURE);

        self::assertStringNotContainsString('script', $stored);
        self::assertStringContainsString('Ada', $stored);
    }

    /**
     * The three-state distinction, driven the way the new panel drives it:
     * absent, then a stored empty string, then absent again. The middle state
     * is reached by clearing a real editor and saving, which is the only path
     * the UI offers — and it must not be mistaken for inheriting.
     */
    public function testTheThreeStatesSurviveARoundTripThroughTheUi(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'aliases@joder.dev');
        $alias   = $this->alias($account, 'other@joder.dev');

        $account->setSetting(Account::SETTING_SIGNATURE, '<p>Account wide</p>');
        $this->em->flush();

        $key   = Account::signatureAliasSetting((int) $alias->id);
        $url   = '/account/' . $account->id . '/signature/' . $alias->id;
        $token = $this->aliasToken($client, $alias->id);

        // 1. absent — this address follows the account.
        self::assertNull($this->reload($account)->getSetting($key));

        // 2. present, HTML of its own.
        $client->request('POST', $url, ['_token' => $token, 'override' => '1']);
        $client->request('POST', $url, ['_token' => $token, 'signature' => '<p>Mine</p>']);

        self::assertResponseRedirects('/settings?section=signature');
        self::assertSame('<p>Mine</p>', $this->reload($account)->getSetting($key));

        // 3. present but empty — deliberately signs with nothing. The panel
        //    says so in words rather than looking like the inheriting row.
        $client->request('POST', $url, ['_token' => $token, 'signature' => '']);

        self::assertResponseRedirects('/settings?section=signature');
        self::assertSame('', $this->reload($account)->getSetting($key));

        $crawler = $client->request('GET', '/settings?section=signature');
        $row     = $crawler->filter(sprintf('[data-signature-alias="%d"]', $alias->id));

        self::assertSame('overrides', $row->attr('data-signature-state'), 'empty is not inherit');
        self::assertSame(1, $row->filter('[contenteditable="true"]')->count(), 'and still has its editor');
        self::assertStringContainsString('signs with nothing', $row->text());

        // ...and back to absent, which is not the same value as ''.
        $client->request('POST', $url, ['_token' => $token, 'inherit' => '1']);

        self::assertResponseRedirects('/settings?section=signature');
        self::assertNull($this->reload($account)->getSetting($key));
    }

    public function testSavingASignatureWithoutACsrfTokenIsRefused(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'nocsrf@joder.dev');

        $this->em->flush();

        $client->request('POST', '/account/' . $account->id . '/signature', [
            'signature' => '<p>Nope</p>',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnAliasOnSomebodyElsesAccountIsRefused(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, 'mine@joder.dev');
        $alias   = $this->alias($account, 'yours@joder.dev');

        $this->em->flush();

        $other = $this->account($user, 'other-account@joder.dev');

        $client->request('POST', '/account/' . $other->id . '/signature/' . $alias->id, [
            '_token'  => $this->aliasToken($client, $alias->id),
            'inherit' => '1',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * The token off the rendered panel, not one minted out of band.
     *
     * A token asked of the manager directly has no session behind it in a
     * functional test, and reading the real one also proves the panel actually
     * draws the control being posted to.
     */
    private function csrf(KernelBrowser $client, string $formAction): string
    {
        $crawler = $client->request('GET', '/settings?section=signature');

        self::assertResponseIsSuccessful();

        $form = $crawler->filter(sprintf('form[action="%s"]', $formAction));

        self::assertGreaterThan(0, $form->count(), sprintf('the panel renders a form posting to %s', $formAction));

        return (string) $form->filter('input[name="_token"]')->attr('value');
    }

    /**
     * The alias row's token, whichever shape the row currently has.
     *
     * Override, revert and save all post to the same route under the same CSRF
     * id — see SignatureController — so one lookup answers for every state the
     * row can be in, including the inheriting one that has no editor.
     */
    private function aliasToken(KernelBrowser $client, int $aliasId): string
    {
        $crawler = $client->request('GET', '/settings?section=signature');

        self::assertResponseIsSuccessful();

        $row = $crawler->filter(sprintf('[data-signature-alias="%d"]', $aliasId));

        self::assertSame(1, $row->count(), 'the panel draws a row for this alias');

        return (string) $row->filter('input[name="_token"]')->first()->attr('value');
    }

    private function reload(Account $account): Account
    {
        $this->em->clear();

        return $this->em->find(Account::class, $account->id);
    }

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
