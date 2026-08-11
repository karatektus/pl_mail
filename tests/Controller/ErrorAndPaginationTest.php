<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\User\UserRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The URLs a person can produce by editing the address bar, and what they
 * should answer.
 *
 * All of these were 500s or nonsense pages. They share a cause worth naming:
 * every one of them is a value that arrived as text in a URL and was handed
 * straight to something that assumed it was already valid — an enum, an entity
 * id, an offset. A URL is user input with no form around it and no validator
 * attached, which is exactly why it is the input that gets forgotten.
 */
final class ErrorAndPaginationTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    /**
     * A non-numeric id must not reach the entity resolver.
     *
     * @return iterable<string, array{string}>
     */
    public static function nonNumericIds(): iterable
    {
        yield 'thread'     => ['/mail/thread/abc'];
        yield 'message'    => ['/mail/message/abc'];
        yield 'label'      => ['/mail/label/abc'];
        yield 'account'    => ['/mail/account/abc'];
        yield 'attachment' => ['/mail/attachment/abc'];
    }

    #[DataProvider('nonNumericIds')]
    public function testNonNumericIdIsNotFoundRatherThanServerError(string $path): void
    {
        $client = $this->loggedInClient();

        $client->request('GET', $path);

        self::assertSame(404, $client->getResponse()->getStatusCode(), $path);
    }

    /**
     * An unknown inbox tab is a mistyped or stale link, not a fault. It used to
     * be MessageCategory::from() and therefore a ValueError and therefore a 500.
     *
     * @return iterable<string, array{string}>
     */
    public static function unknownTabs(): iterable
    {
        yield 'nonsense'  => ['/mail/inbox?tab=quatsch'];
        yield 'empty'     => ['/mail/inbox?tab='];
        yield 'numeric'   => ['/mail/inbox?tab=7'];
    }

    #[DataProvider('unknownTabs')]
    public function testUnknownInboxTabFallsBackToPrimary(string $path): void
    {
        $client = $this->loggedInClient();

        $client->request('GET', $path);

        self::assertSame(200, $client->getResponse()->getStatusCode(), $path);
    }

    /**
     * Past the last page, the list redirects back onto a page that exists.
     *
     * The count is whatever the seeded database holds, which is why this asserts
     * on the redirect rather than on a row count: the invariant is that the URL
     * stops claiming a page number the list cannot serve, at any list size.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function pagesPastTheEnd(): iterable
    {
        yield 'inbox'  => ['/mail/inbox?page=999', '/mail/inbox'];
        yield 'trash'  => ['/mail/trash?page=999', '/mail/trash'];
        yield 'sent'   => ['/mail/sent?page=5', '/mail/sent'];
        yield 'drafts' => ['/mail/drafts?page=5', '/mail/drafts'];
    }

    #[DataProvider('pagesPastTheEnd')]
    public function testPagePastTheEndRedirectsOntoTheList(string $path, string $expectedPrefix): void
    {
        $client = $this->loggedInClient();

        $client->request('GET', $path);
        $response = $client->getResponse();

        self::assertTrue($response->isRedirect(), $path . ' should redirect');
        self::assertStringStartsWith(
            $expectedPrefix,
            (string) $response->headers->get('Location'),
            $path,
        );

        // And the page it lands on renders.
        $client->followRedirect();
        self::assertSame(200, $client->getResponse()->getStatusCode(), $path);
    }

    /**
     * The lower bound was always clamped and must stay clamped — `?page=0` and
     * `?page=-3` render page one rather than computing a negative offset.
     */
    public function testPageBelowOneStillRendersTheFirstPage(): void
    {
        $client = $this->loggedInClient();

        foreach (['/mail/inbox?page=0', '/mail/inbox?page=-3', '/mail/inbox?page=nonsense'] as $path) {
            $client->request('GET', $path);
            self::assertSame(200, $client->getResponse()->getStatusCode(), $path);
        }
    }

    /**
     * A page that already exists is left alone. Without this the redirect could
     * be unconditional and every test above would still pass.
     */
    public function testAnExistingPageIsNotRedirected(): void
    {
        $client = $this->loggedInClient();

        $client->request('GET', '/mail/inbox');

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * The error templates render, and render translated.
     *
     * Rendered directly rather than by provoking a real error: with debug on —
     * which is how the test kernel runs — Symfony shows the exception page and
     * these templates never execute, so the only way to catch a typo in one is
     * to ask Twig for it.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function errorTemplates(): iterable
    {
        yield '404' => ['bundles/TwigBundle/Exception/error404.html.twig', 'errors.page.not_found.heading'];
        yield '500' => ['bundles/TwigBundle/Exception/error500.html.twig', 'errors.page.server.heading'];
        yield 'fallback' => ['bundles/TwigBundle/Exception/error.html.twig', 'errors.page.server.heading'];
    }

    #[DataProvider('errorTemplates')]
    public function testErrorTemplateRendersBrandedAndTranslated(string $template, string $key): void
    {
        self::bootKernel();

        $container  = static::getContainer();
        $translator = $container->get('translator');
        $html       = $container->get('twig')->render($template);

        $heading = $translator->trans($key);

        self::assertStringContainsString($heading, $html, 'the localised heading is missing');
        self::assertNotSame($key, $heading, 'the translation key has no entry');

        // Branded, not the stock Symfony page: it carries the app shell and a
        // way back, and no raw translation key leaked into the markup.
        self::assertStringContainsString('/mail/inbox', $html);
        self::assertStringNotContainsString('errors.page.', $html);
    }

    private function loggedInClient(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = static::createClient();

        $user = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);

        return $client;
    }
}
