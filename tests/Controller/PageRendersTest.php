<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\User\UserRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

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
 * The user panel is in the list below rather than excluded from it. It used to
 * be the one route this test refused to walk — `/admin/user`, whose template
 * had never existed — and the exclusion outlived the fix by long enough that
 * the panel was reported missing while it was sitting in the admin nav. A route
 * left out of a render sweep is a route nothing tells you about.
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
        yield 'spam' => ['/mail/spam'];
        yield 'search' => ['/mail/search?q=test'];
        yield 'settings' => ['/settings'];
        yield 'settings appearance' => ['/settings?section=appearance'];
        yield 'settings accounts' => ['/settings?section=accounts'];
        yield 'settings health' => ['/settings?section=health'];
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
        // The AI section renders whether or not an administrator has switched
        // anything on: a feature that is off is a row with a sentence instead
        // of a switch, and that branch is the one nothing else walks.
        yield 'settings ai' => ['/settings?section=ai'];
        yield 'settings insights' => ['/settings?section=insights'];
        yield 'filters list' => ['/settings/filters/list'];
        yield 'filters editor' => ['/settings/filters/new'];
        yield 'label editor' => ['/labels/new'];
        yield 'account editor' => ['/account/new'];
        // Modal fragments with selects in them, which is why they are here:
        // testEverySelectIsEnhanced below walks this same list.
        yield 'calendar event editor' => ['/calendar/event/new'];
        yield 'calendar ics import' => ['/calendar/ics/import'];
        yield 'booking page editor' => ['/settings/sharing/booking/new'];
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
        yield 'admin insight reports' => ['/admin/insight-reports'];
        // The section wrapper as well as the frame, because the nav entry
        // renders a pending-count badge that the frame itself never draws —
        // a badge that 500s on an empty pile would break only this URL.
        yield 'admin insight reports section' => ['/admin?section=insight-reports'];
        // Was excluded from this provider for as long as it was broken, and
        // then for a good while after it was not — see the note above. The
        // create form is listed separately because it is the only half that
        // renders a password field, and the edit half cannot be reached from a
        // static provider: it needs a user id.
        yield 'admin users' => ['/admin/users'];
        yield 'admin users, searched' => ['/admin/users?search=admin'];
        yield 'admin user create form' => ['/admin/users/create'];
        yield 'admin push deliveries' => ['/admin/push/deliveries'];
        // Every filter at once, and each one spelled wrong: the delivery
        // browser is reached by editing a query string, so an unparseable
        // transport or outcome has to fall back to "no filter" rather than
        // 400 — and a page that 500s on a typo is a page nobody diagnoses
        // anything with.
        yield 'admin push deliveries, nonsense filters' => ['/admin/push/deliveries?usr=nope&transport=fcmm&outcome=exploded&page=-3'];
        yield 'admin config backup' => ['/admin/config-backup'];
        // The one query string this frame reads. An unknown value has to render
        // the panel without an error rather than 500 on a missing translation
        // key, for the same reason the delivery filters above fall back.
        yield 'admin config backup, export refused' => ['/admin/config-backup?error=password-mismatch'];
        yield 'admin config backup, nonsense error' => ['/admin/config-backup?error=made-up'];
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

    /**
     * No page renders a select the browser will draw its own popup for.
     *
     * The decision is that there are no native selects in the UI: the popup is
     * the one part of a form control CSS cannot reach, so a themed app with a
     * native dropdown in it looks half-finished in five of the six themes. Every
     * select is enhanced by ui--select instead — through the form theme when a
     * form rendered it, through the _select.html.twig macro when a template did.
     *
     * Written as a sweep rather than three spot checks because the failure mode
     * is a select nobody remembered: the previous pass at this problem styled
     * `select-field` onto thirteen of them and missed the five the rule editor
     * builds in JavaScript. A sweep notices the fourteenth.
     */
    #[DataProvider('pages')]
    public function testEverySelectIsEnhanced(string $path): void
    {
        $client = static::createClient();

        $user = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);
        $crawler = $client->request('GET', $path);

        self::assertSame(200, $client->getResponse()->getStatusCode(), $path);

        $bare = array_values(array_filter($crawler->filter('select')->each(
            static function (Crawler $select): ?string {
                $controllers = $select->attr('data-controller') ?? '';

                // Compose's address fields. They have a Tom Select of their own,
                // with contact rows and chips, and are the only multi-selects here.
                if (null !== $select->attr('multiple')) {
                    return null;
                }

                // The provider preset, enhanced by UX Autocomplete rather than
                // by us because it fetches its choices over the wire.
                if (str_contains($controllers, 'symfony--ux-autocomplete')) {
                    return null;
                }

                // Never shown: the "From" line is a custom menu built from
                // these options and this is only what the form posts.
                if (null !== $select->attr('data-compose--compose-target')) {
                    return null;
                }

                if (str_contains($controllers, 'ui--select')) {
                    return null;
                }

                return $select->attr('name') ?? $select->attr('id') ?? '(anonymous)';
            },
        )));

        self::assertSame([], $bare, sprintf('%s renders native select(s): %s', $path, implode(', ', $bare)));
    }
}
