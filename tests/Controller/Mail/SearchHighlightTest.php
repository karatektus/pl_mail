<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A search result says why it is a search result.
 *
 * The row used to show the first hundred characters of the newest message,
 * which for most hits does not contain the term that found it: the list
 * asserted a match and then showed something else, and the only way to find
 * out why a conversation was there was to open it. The fragment now comes from
 * `ts_headline`, which is a window of the body AROUND the match, and every
 * occurrence in it — and in the subject — is marked.
 *
 * Two claims, and the second is the one that constrains the implementation:
 *
 *  1. the fragment is match-aware, so the words beside the term are shown and
 *     the opening of the body is not;
 *  2. NOTHING ELSE CHANGES. The row partial is shared with ten other lists and
 *     with the per-row turbo-streams; all of them pass no highlight and must
 *     render exactly as they did. The inbox case below is what holds that.
 *
 * Against the real database because `ts_headline` is the subject: which words
 * land either side of a match, and whether a short subject survives being
 * fragmented at all, are Postgres's answers and not ours.
 */
final class SearchHighlightTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    /** Far enough down the body that the fragment around the term excludes it. */
    private const string OPENING = 'Kopfzeile ganz oben und sonst nichts.';

    private const string TERM = 'chargecloud';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // The fixtures live in an open transaction on this connection, and a
        // reboot between requests would take it with them.
        $this->client->disableReboot();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The point of the whole feature: the preview moves to the match.
     */
    public function testTheSnippetIsAWindowAroundTheMatchRatherThanTheOpeningOfTheBody(): void
    {
        $this->seedThread('Oak for the alcove');

        $html = $this->search(self::TERM);

        self::assertStringContainsString(
            '<mark>chargecloud</mark>',
            $html,
            'the term the search matched on is not marked in the row',
        );

        self::assertStringContainsString(
            'invoice for August',
            $html,
            'the words beside the match are what make the row explain itself',
        );

        self::assertStringNotContainsString(
            self::OPENING,
            $html,
            'the row is still previewing the top of the body instead of the match',
        );
    }

    /**
     * The subject is highlighted in place, whole.
     *
     * `ts_headline` fragments a subject as readily as a body — at the default
     * ShortWord it answers "Oak for the alcove" searched for `alcove` with the
     * single word "alcove" — so a row printing the headline unguarded would
     * silently rewrite the subject line. Both halves are asserted here: the
     * mark, and the words around it that must have survived.
     */
    public function testTheSubjectIsHighlightedWithoutBeingRewritten(): void
    {
        $this->seedThread('Oak for the alcove');

        $html = $this->search('alcove');

        self::assertStringContainsString('<mark>alcove</mark>', $html);
        self::assertStringContainsString('Oak for the <mark>alcove</mark>', $html);
    }

    /**
     * An operator-only search has no term, so there is nothing to mark and no
     * headline query to pay for — the row falls back to the preview every other
     * list shows.
     */
    public function testAnOperatorOnlySearchHighlightsNothing(): void
    {
        $this->seedThread('Oak for the alcove');

        $html = $this->search('from:sender@example.test');

        self::assertStringContainsString(self::OPENING, $html, 'the plain preview should be back');
        self::assertStringNotContainsString('<mark>', $html);
    }

    /**
     * The other ten lists that share this row partial.
     *
     * They pass no highlight, so nothing may be marked in them — a `<mark>` in
     * the inbox would mean the search's per-page data had found its way onto
     * the shared MessageThread entity, which is the failure the map-keyed-by-id
     * shape exists to prevent.
     */
    public function testAnOrdinaryListIsUntouched(): void
    {
        $this->seedThread('Oak for the alcove');

        $this->client->request('GET', '/mail/inbox');

        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString(self::OPENING, $html, 'the inbox lost its preview');
        self::assertStringNotContainsString('<mark>', $html);
    }

    /**
     * A message whose text part is markup.
     *
     * The fragment is body_text, which is the half of a mail some senders fill
     * with HTML — and Twig cannot escape it, because it arrives as Markup that
     * has already been escaped. If that escaping is ever dropped the tag lands
     * in the list as an element, so this is the row-level twin of the JMAP
     * test: see SearchSnippetGetMethodTest.
     */
    public function testMarkupInAMatchedTextPartIsShownAsTextInTheRow(): void
    {
        $this->seedThread(
            'Rechnung',
            'Anbei <img src=x onerror=alert(1)> die chargecloud Rechnung fuer August.',
        );

        $html = $this->search(self::TERM);

        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringNotContainsString('<img src=x', $html);
    }

    // ── the fallback, for hits `ts_headline` structurally cannot mark ─────

    /**
     * A term buried inside a URL.
     *
     * Postgres's `english` parser makes the whole link ONE token of type `url`,
     * and that token does not stem to `chargecloud`, so `ts_headline` marks
     * nothing — while the search found the row anyway, through the substring
     * arm of the query. Measured, not reasoned about: see the table in
     * SearchHighlighter::fallback().
     *
     * The padding is load-bearing here for the same reason as in the primary
     * test: without it the plain preview would already show these words and the
     * assertion would pass against a fallback that does nothing.
     */
    public function testATermBuriedInAUrlIsMarkedEvenThoughTsHeadlineCannotSeeIt(): void
    {
        $this->seedThread(
            'Stellenangebote',
            self::OPENING.' '
            .str_repeat('padding word here plus more filler. ', 10)
            .'Jobangebot ansehen https://www.linkedin.com/jobs/view/4454199659/company=chargecloud jetzt bewerben.',
        );

        $html = $this->search(self::TERM);

        self::assertStringContainsString(
            '<mark>chargecloud</mark>',
            $html,
            'the term that found the row is inside a URL and was not marked',
        );

        self::assertStringContainsString(
            'Jobangebot ansehen',
            $html,
            'the fallback fragment should carry the words beside the match',
        );

        self::assertStringNotContainsString(
            self::OPENING,
            $html,
            'the row is still previewing the top of the body instead of the match',
        );
    }

    /**
     * A term that is one component of a hostname.
     *
     * `chargecloud.de` is a single `host` lexeme. The row is found through the
     * weight-D token-parts arm — Version20260818120000 — which indexes exactly
     * the pieces `ts_headline` refuses to mark.
     */
    public function testATermInsideAHostnameIsMarkedInBothSubjectAndPreview(): void
    {
        $this->seedThread(
            'Neue Jobs bei chargecloud.de und anderen Firmen',
            self::OPENING.' '
            .str_repeat('padding word here plus more filler. ', 10)
            .'Stellen findest du jederzeit auf chargecloud.de und dort auch bei chargecloud.de/jobs.',
        );

        $html = $this->search(self::TERM);

        self::assertStringContainsString(
            'Neue Jobs bei <mark>chargecloud</mark>.de und anderen Firmen',
            $html,
            'the subject holds the term inside a hostname and was not marked in place',
        );

        self::assertStringContainsString(
            'Stellen findest du jederzeit auf',
            $html,
            'the preview should be the window around the hit, not the top of the body',
        );
    }

    /**
     * Two hits in one window, both marked.
     *
     * A fragment that plainly contains the term twice with one of them marked
     * reads as the highlighter having given up halfway. Gmail marks every
     * occurrence and so does this.
     */
    public function testEveryOccurrenceInTheWindowIsMarkedAndNotJustTheFirst(): void
    {
        $this->seedThread(
            'Stellen',
            'Siehe https://jobs.example.test/x/firma=chargecloud sowie '
            .'https://jobs.example.test/y/firma=chargecloud fuer weitere Angebote.',
        );

        $html = $this->search(self::TERM);

        self::assertSame(
            2,
            substr_count($html, '<mark>chargecloud</mark>'),
            'both occurrences inside the fragment should be marked',
        );
    }

    /**
     * The term is genuinely not in the subject or the body.
     *
     * This row is found on the sender's NAME, which is in `search_vector` and in
     * neither of the two fields a fragment is built from. `ts_headline` marks
     * nothing, the fallback finds nothing, and the row must keep the preview
     * every other list shows — the fallback may not invent a reason.
     */
    public function testATermAbsentFromSubjectAndBodyStillFallsBackToThePlainPreview(): void
    {
        $this->seedThread('Oak for the alcove');

        $html = $this->search('Sender');

        self::assertStringContainsString(
            self::OPENING,
            $html,
            'the plain preview should be back when neither field holds the term',
        );

        self::assertStringNotContainsString('<mark>', $html);
    }

    /**
     * Markup in a fragment the fallback built, not `ts_headline`.
     *
     * The v0.2.34 security property is that highlight HTML is escaped first and
     * marked second. A fallback that assembled `<mark>` by concatenation would
     * pass every "does it contain a mark" test in this file and ship the
     * sender's markup to the page as elements. This is the test that says which
     * of the two happened.
     */
    public function testMarkupAroundAFallbackFragmentIsEscapedRatherThanRendered(): void
    {
        $this->seedThread(
            'Rechnung',
            'Anbei <img src=x onerror=alert(1)> siehe '
            .'https://example.test/jobs/view/4454199659/company=chargecloud heute.',
        );

        $html = $this->search(self::TERM);

        self::assertStringContainsString('<mark>chargecloud</mark>', $html, 'the fallback did not run');
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringNotContainsString('<img src=x', $html);
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    /** The rendered search page for a query. */
    private function search(string $query): string
    {
        $this->client->request('GET', '/mail/search?q='.urlencode($query));

        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * One conversation whose body buries the term a long way from the top.
     *
     * The padding is what makes the first claim testable: with the term in the
     * opening line, the old preview and the new fragment would show the same
     * words and the test would pass against both.
     */
    private function seedThread(string $subject, ?string $body = null): MessageThread
    {
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower($subject);
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable('-2 hours');
        $thread->category          = MessageCategory::Primary;
        $thread->messageCount      = 1;
        $thread->unreadCount       = 0;
        $thread->addLabel($this->inbox);

        $this->em->persist($thread);

        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->subject        = $subject;
        $message->fromAddress    = 'sender@example.test';
        $message->fromName       = 'Sender';
        $message->receivedAt     = new DateTimeImmutable('-2 hours');
        $message->sentAt         = $message->receivedAt;
        $message->seenAt         = $message->receivedAt;
        $message->flags          = [];
        $message->hasAttachments = false;
        $message->bodyText       = $body ?? (
            self::OPENING.' '
            .str_repeat('padding word here plus more filler. ', 10)
            .'The chargecloud invoice for August is attached and needs paying.'
        );

        $thread->addMessage($message);
        $this->em->persist($message);

        $this->em->flush();
        $this->em->clear();

        return $thread;
    }
}
