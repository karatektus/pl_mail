<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Repository\User\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A list refresh gets the list, not the page it lives in.
 *
 * The poll used to ask for the current URL and get the whole document back —
 * topbar, sidebar, calendar pane, reading pane — then parse it client-side, lift
 * one frame out and discard the rest. Measured at 80 KB a time, eight times in
 * ten seconds of an idle inbox.
 *
 * The header is ours rather than Turbo's `Turbo-Frame`, and that is load-bearing:
 * Turbo sets its header on ordinary frame navigations too, and reads the
 * response's <title> to update history when it advances. Answering those with a
 * bare fragment would quietly stop the tab title changing between folders, so
 * the last test here pins that a Turbo frame navigation still gets a document.
 */
final class ListFragmentTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';
    private const string FRAGMENT_HEADER = 'HTTP_X_LIST_FRAGMENT';

    private function signedIn(): KernelBrowser
    {
        $client = static::createClient();

        $user = static::getContainer()
            ->get(UserRepository::class)
            ->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);

        return $client;
    }

    public function testAFragmentRequestAnswersWithTheListFrameAlone(): void
    {
        $client = $this->signedIn();

        $client->request('GET', '/mail/inbox', server: [self::FRAGMENT_HEADER => 'inbox-list-frame']);

        self::assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString(
            'id="inbox-list-frame"',
            $body,
            'the fragment is the frame, so the frame has to be in it',
        );

        // The point of the exercise: none of the document around it.
        self::assertStringNotContainsString('<html', $body);
        self::assertStringNotContainsString('<body', $body);
        self::assertStringNotContainsString('id="sidebar"', $body);
        self::assertStringNotContainsString('<title', $body);
    }

    /**
     * The saving, asserted as a fact rather than assumed from the absence of
     * tags above.
     */
    public function testTheFragmentIsAFractionOfThePage(): void
    {
        $client = $this->signedIn();

        $client->request('GET', '/mail/inbox');
        $page = strlen((string) $client->getResponse()->getContent());

        $client->request('GET', '/mail/inbox', server: [self::FRAGMENT_HEADER => 'inbox-list-frame']);
        $fragment = strlen((string) $client->getResponse()->getContent());

        self::assertLessThan(
            $page / 2,
            $fragment,
            sprintf('the fragment (%d bytes) should be far smaller than the page (%d bytes)', $fragment, $page),
        );
    }

    /**
     * An ordinary visit is untouched — the fragment path is opt-in, and every
     * page that is not the poll still gets a document.
     */
    public function testAnOrdinaryVisitStillGetsTheWholePage(): void
    {
        $client = $this->signedIn();

        $client->request('GET', '/mail/inbox');

        self::assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('<html', $body);
        self::assertStringContainsString('id="inbox-list-frame"', $body);
    }

    /**
     * Turbo's own frame navigations must keep getting a document, or the tab
     * title stops following the folder you are in.
     */
    public function testATurboFrameNavigationStillGetsADocumentWithATitle(): void
    {
        $client = $this->signedIn();

        $client->request('GET', '/mail/inbox', server: ['HTTP_TURBO_FRAME' => 'inbox-list-frame']);

        self::assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('<title', $body, 'Turbo advances history from the response title');
        self::assertStringContainsString('id="inbox-list-frame"', $body);
    }

    // ── the flag that stops Back showing an empty list ────────────────────

    public function testAListPageMarksItsFrameAsRendered(): void
    {
        $client = $this->signedIn();

        $client->request('GET', '/mail/inbox');

        self::assertStringContainsString(
            'data-list-rendered="1"',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testTheTitleNamesTheCountItShows(): void
    {
        $client = $this->signedIn();

        $client->request('GET', '/mail/inbox');

        self::assertStringContainsString(
            'name="title-count-key" content="role:inbox"',
            (string) $client->getResponse()->getContent(),
            'the sidebar rewrites the title from this key after a sync',
        );
    }
}
