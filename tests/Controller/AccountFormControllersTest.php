<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\User\UserRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The account form's Stimulus controllers are actually attached.
 *
 * account/_fields.html.twig addresses `settings--imap-preset` and
 * `settings--connection-test` through targets and actions, but declares
 * neither: both hang off the <form> in whatever shell renders the partial.
 * Nothing fails when that declaration is missing — Stimulus simply never
 * connects, so choosing a provider quietly stops prefilling the servers and
 * Test connection quietly does nothing.
 *
 * That is precisely what happened when the controllers were regrouped into
 * directories: the identifiers gained a `settings--` prefix everywhere except
 * on these two forms, and the feature was dead for as long as nobody noticed.
 * A string comparison is a crude test; it is also the only kind that would have
 * caught it.
 */
final class AccountFormControllersTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    /** @return iterable<string, array{string}> */
    public static function formsRenderingTheFields(): iterable
    {
        yield 'settings modal' => ['/account/new'];
        yield 'setup wizard step' => ['/onboarding/account'];
    }

    #[DataProvider('formsRenderingTheFields')]
    public function testTheFieldsAreInsideBothControllers(string $path): void
    {
        $client = static::createClient();

        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);
        $client->request('GET', $path);

        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();

        // The targets are the proof the partial rendered at all; without them
        // the controller assertions below would pass vacuously.
        self::assertStringContainsString('data-settings--imap-preset-target', $html, $path);
        self::assertStringContainsString('data-settings--connection-test-target', $html, $path);

        foreach (['settings--imap-preset', 'settings--connection-test'] as $identifier) {
            self::assertMatchesRegularExpression(
                '/data-controller="[^"]*'.preg_quote($identifier, '/').'\b/',
                $html,
                sprintf('%s does not declare %s, so it will never connect', $path, $identifier),
            );
        }

        // Declaring the controller is not enough. Without a URL the test
        // controller posts to the document it happens to be on and tries to
        // parse the HTML that comes back as JSON — which is exactly what the
        // wizard's copy did.
        foreach (['url', 'csrf'] as $value) {
            self::assertStringContainsString(
                sprintf('data-settings--connection-test-%s-value="', $value),
                $html,
                sprintf('%s attaches the connection test without its %s', $path, $value),
            );
        }
    }
}
