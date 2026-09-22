<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Mail;

use App\Jmap\Method\Mail\SearchSnippetGetMethod;
use App\Tests\Jmap\JmapTestCase;

/**
 * A snippet is message content, and it may not carry markup of its own.
 *
 * This class docblock made that claim for months while the opposite shipped.
 * `ts_headline` is a highlighter, not an escaper: it inserts StartSel and
 * StopSel around the matching lexemes and returns the rest of the document
 * byte for byte — so asking it for `StartSel=<mark>` produces a string in
 * which the sender's `<img src=x onerror=…>` and our `<mark>` are the same
 * kind of thing, and the "does it contain <mark>" check that stood in for
 * escaping cannot tell them apart.
 *
 * Per RFC 8621 §5 these strings ARE HTML, so a client is right to render them.
 * That is what makes this a security bug rather than a display one: the mail
 * does not have to be opened for the markup to run.
 *
 * Against the real database rather than a double, because the thing under test
 * is what Postgres actually does with a document containing markup — which is
 * not what the reasonable guess says. Well-formed tags are dropped by the
 * parser (`<b>` vanishes, leaving a double space); malformed ones, which is
 * most of what a real sender produces, come through untouched. A PHP fake of
 * `ts_headline` would have to encode one of those guesses and would then agree
 * with itself forever.
 *
 * The same argument is now what makes the second half of this file worth
 * running. A term inside a link or a hostname is one Postgres decides it cannot
 * mark, so these tests are asserting over a fragment PHP assembled out of raw
 * `body_text` — a second producer of snippet HTML, and therefore a second way
 * of shipping the sender's markup if it ever stopped going through the one
 * place that escapes.
 */
final class SearchSnippetGetMethodTest extends JmapTestCase
{
    /**
     * Unclosed quotes and an unquoted attribute, which is what stops the
     * Postgres parser from classifying this as a tag and dropping it. Verified
     * against Postgres 18: it comes back verbatim.
     */
    private const string SUBJECT = 'Angebot <b>chargecloud</b> und <img src=x onerror=alert(1)> im Anhang';

    private const string BODY = 'Anbei <img src=x onerror=alert(1)> die chargecloud Rechnung fuer August.';

    /**
     * The term buried in a link, which the `english` parser reads as ONE token
     * of type `url` — so no lexeme in it equals `chargecloud` and `ts_headline`
     * has nothing it is allowed to mark. The markup in front of it is there on
     * purpose: the fallback that marks this has to escape exactly as the
     * primary path does, and a fragment it built is the one string in this file
     * Postgres never saw.
     */
    private const string BODY_WITH_URL = 'Anbei <img src=x onerror=alert(1)> Jobangebot ansehen '
        .'https://www.linkedin.com/jobs/view/4454199659/company=chargecloud heute.';

    /** The same structural blindness over a hostname, which is one `host` token. */
    private const string SUBJECT_WITH_HOST = 'Neue Jobs bei chargecloud.de und anderen Firmen';

    private SearchSnippetGetMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        $this->method = self::getContainer()->get(SearchSnippetGetMethod::class);
    }

    public function testMarkupInAMatchedSubjectIsEscapedRatherThanShippedAsHtml(): void
    {
        $snippet = $this->snippetFor(self::SUBJECT, self::BODY);

        $subject = (string) $snippet['subject'];

        self::assertStringContainsString(
            '<mark>chargecloud</mark>',
            $subject,
            'the term the search matched on is what <mark> is for',
        );

        self::assertStringNotContainsString(
            '<img',
            $subject,
            'the sender\'s markup reached a client as live HTML — ts_headline does not escape',
        );

        self::assertStringContainsString(
            '&lt;img src=x onerror=alert(1)&gt;',
            $subject,
            'escaped, the tag is shown; it is the one thing a snippet must not be able to do',
        );
    }

    /** The same document, the same treatment — the body is the longer half. */
    public function testMarkupInAMatchedBodyIsEscapedRatherThanShippedAsHtml(): void
    {
        $snippet = $this->snippetFor(self::SUBJECT, self::BODY);

        $preview = (string) $snippet['preview'];

        self::assertStringContainsString('<mark>chargecloud</mark>', $preview);
        self::assertStringNotContainsString('<img', $preview);
        self::assertStringContainsString('&lt;img', $preview);
    }

    /**
     * `ts_headline` answers with the opening words of a field that holds no
     * hit, and a preview that is just the first line again says nothing about
     * why the message came back. The absence of a marker is the only signal
     * there is, so it has to mean null and not "here is some text anyway".
     *
     * This now also guards the fallback, which is handed this subject's raw
     * text and could just as easily answer with a fragment of it. A genuine
     * null has to survive BOTH of them, or the field stops meaning anything.
     */
    public function testAFieldWithNoHitIsNullRatherThanItsOpeningWords(): void
    {
        $snippet = $this->snippetFor('Nothing to do with it', self::BODY);

        self::assertNull($snippet['subject']);
        self::assertNotNull($snippet['preview']);
    }

    /**
     * The term inside a link, marked — and the whole reason this method now
     * fetches raw text at all.
     *
     * The search returns this row: `search_vector`'s weight-D arm indexes the
     * pieces of compound tokens, and the substring pass catches the rest. What
     * it did NOT do was say why, because `ts_headline` sees one `url` lexeme
     * and refuses to mark a word inside it — so a client got a null snippet for
     * a row it had just been told matched.
     */
    public function testATermInsideAUrlIsMarked(): void
    {
        $preview = (string) $this->snippetFor('Nothing to do with it', self::BODY_WITH_URL)['preview'];

        self::assertStringContainsString('<mark>chargecloud</mark>', $preview);

        self::assertStringContainsString(
            'company=<mark>chargecloud</mark>',
            $preview,
            'the link is carried whole — half a URL is a lie about where it goes',
        );
    }

    /**
     * The fragment the FALLBACK built is escaped too, which is the one thing
     * this file exists to hold.
     *
     * Not a duplicate of the two tests above it: those assert over a string
     * `ts_headline` produced, and Postgres at least dropped the well-formed
     * tags on its way through. This string was assembled in PHP out of raw
     * `body_text`, so nothing has touched the markup before SearchHighlighter
     * escapes it — a second producer of snippets is a second chance to ship the
     * sender's HTML, and that is precisely what must not have been added.
     */
    public function testAFragmentBuiltByTheFallbackIsEscapedLikeAnyOther(): void
    {
        $preview = (string) $this->snippetFor('Nothing to do with it', self::BODY_WITH_URL)['preview'];

        self::assertStringNotContainsString('<img', $preview);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $preview);
    }

    /** The same blindness over a hostname, in the field a reader looks at first. */
    public function testATermInsideAHostnameIsMarkedInTheSubject(): void
    {
        $subject = (string) $this->snippetFor(self::SUBJECT_WITH_HOST, 'Nothing to do with it')['subject'];

        self::assertSame('Neue Jobs bei <mark>chargecloud</mark>.de und anderen Firmen', $subject);
    }

    /**
     * Two characters are not looked for, matching FreeTextCompiler's floor.
     *
     * `de` is in this subject twice over — as the tail of the hostname and
     * inside "anderen" — and marking either would scatter `<mark>` through the
     * middles of ordinary German words. The query does not search for a needle
     * that short either, so a snippet claiming to explain the row with one
     * would be explaining something that did not happen.
     */
    public function testATwoCharacterTermIsNotWorthMarking(): void
    {
        $snippet = $this->snippetFor(self::SUBJECT_WITH_HOST, 'Nothing to do with it', 'de');

        self::assertNull($snippet['subject']);
    }

    /**
     * One message, seeded with the given text, put through the method.
     *
     * @return array{emailId: string, subject: ?string, preview: ?string}
     */
    private function snippetFor(string $subject, string $body, string $freeText = 'chargecloud'): array
    {
        $message           = $this->receivedMessage();
        $message->subject  = $subject;
        $message->bodyText = $body;

        $this->em->flush();

        $result = $this->method->handle(
            [
                'accountId' => $this->accountId(),
                'emailIds'  => [(string) $message->id],
                'filter'    => ['text' => $freeText],
            ],
            $this->context(),
        );

        /** @var list<array{emailId: string, subject: ?string, preview: ?string}> $list */
        $list = $result['list'];

        self::assertCount(1, $list, 'the message seeded above should be the one snippet');

        return $list[0];
    }
}
