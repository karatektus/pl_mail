<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\User\UserRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Every authenticated page answers 200 for an admin with no mail account.
 *
 * Cheap, wide coverage: a controller or template broken by a rename, a moved
 * class or a missing partial shows up here as a 500 instead of in the browser.
 * It asserts nothing about the contents — the e2e suite does that.
 *
 * Needs the seeded admin (`app:test:seed-user --admin`); skips itself without
 * one so a fresh checkout does not fail on missing fixtures.
 *
 * `/admin/user` is deliberately absent — it 500s today for reasons that have
 * nothing to do with rendering (its template has never existed). See "Findings
 * worth fixing" in todo.md; add it back here once it answers 200.
 */
final class PageRendersTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    /** @return iterable<string, array{string}> */
    public static function pages(): iterable
    {
        yield 'inbox' => ['/mail/inbox'];
        yield 'starred' => ['/mail/starred'];
        yield 'sent' => ['/mail/sent'];
        yield 'drafts' => ['/mail/drafts'];
        yield 'trash' => ['/mail/trash'];
        yield 'archive' => ['/mail/archive'];
        yield 'search' => ['/mail/search?q=test'];
        yield 'settings' => ['/settings'];
        yield 'settings appearance' => ['/settings?section=appearance'];
        yield 'settings accounts' => ['/settings?section=accounts'];
        yield 'settings filters' => ['/settings?section=filters'];
        yield 'settings integrations' => ['/settings?section=integrations'];
        yield 'settings aliases' => ['/settings?section=aliases'];
        yield 'settings app passwords' => ['/settings?section=app-passwords'];
        yield 'settings notifications' => ['/settings?section=notifications'];
        yield 'settings security' => ['/settings?section=security'];
        // The enrolment panel, which is where the QR is rendered — a broken
        // renderer only shows up once something asks it for an image.
        yield 'settings security enrolling' => ['/settings?section=security&enrol=1'];
        yield 'settings general' => ['/settings?section=general'];
        yield 'filters list' => ['/settings/filters/list'];
        yield 'filters editor' => ['/settings/filters/new'];
        yield 'label editor' => ['/labels/new'];
        yield 'account editor' => ['/account/new'];
        yield 'admin dashboard' => ['/admin'];
        yield 'admin logs' => ['/admin/logs'];
        yield 'admin db' => ['/admin/db'];
        // Frames, not pages: /admin loads them lazily, so a template that only
        // breaks once it has data breaks nowhere else in this suite.
        yield 'admin live frame' => ['/admin/live'];
        yield 'admin queue backlog' => ['/admin/queues/waiting'];
        yield 'admin queue backlog, filtered' => ['/admin/queues/waiting?q=sync&offset=25'];
        yield 'admin integrations' => ['/admin/integrations'];
        yield 'admin push' => ['/admin/push'];
        yield 'admin push deliveries' => ['/admin/push/deliveries'];
        // Every filter at once, and each one spelled wrong: the delivery
        // browser is reached by editing a query string, so an unparseable
        // transport or outcome has to fall back to "no filter" rather than
        // 400 — and a page that 500s on a typo is a page nobody diagnoses
        // anything with.
        yield 'admin push deliveries, nonsense filters' => ['/admin/push/deliveries?usr=nope&transport=fcmm&outcome=exploded&page=-3'];
        // The wizard renders into the modal frame, so these are fragments
        // rather than pages — but a broken step template still shows up as a
        // 500 here, which is the point.
        yield 'onboarding profile step' => ['/onboarding/profile'];
        yield 'onboarding account step' => ['/onboarding/account'];
        yield 'onboarding appearance step' => ['/onboarding/appearance'];
        yield 'onboarding security step' => ['/onboarding/security'];
        yield 'onboarding security step, enrolling' => ['/onboarding/security?enrol=1'];
        // These render whatever is already configured — a step being done no
        // longer hides it — so they answer 200 regardless of what the e2e suite
        // last left in the database.
        yield 'onboarding admin mail step' => ['/onboarding/admin-mail'];
        yield 'onboarding admin integrations step' => ['/onboarding/admin-integrations'];
        // Not the integrations step: it is the one that still answers 404 by
        // design, when an admin has made nothing connectable, and whether this
        // database has anything depends on what the e2e suite last did.
        yield 'settings profile' => ['/settings?section=profile'];
        // Was a 500 on a fresh install: no account, and Message::setAccount()
        // is not nullable. See "Findings worth fixing" in todo.md.
        yield 'compose new' => ['/compose/new'];
    }

    #[DataProvider('pages')]
    public function testPageRenders(string $path): void
    {
        $client = static::createClient();

        $user = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);
        $client->request('GET', $path);

        self::assertSame(200, $client->getResponse()->getStatusCode(), $path);
    }
}
