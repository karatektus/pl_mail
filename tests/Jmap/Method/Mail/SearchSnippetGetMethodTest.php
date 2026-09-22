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
     */
    public function testAFieldWithNoHitIsNullRatherThanItsOpeningWords(): void
    {
        $snippet = $this->snippetFor('Nothing to do with it', self::BODY);

        self::assertNull($snippet['subject']);
        self::assertNotNull($snippet['preview']);
    }

    /**
     * One message, seeded with the given text, put through the method.
     *
     * @return array{emailId: string, subject: ?string, preview: ?string}
     */
    private function snippetFor(string $subject, string $body): array
    {
        $message           = $this->receivedMessage();
        $message->subject  = $subject;
        $message->bodyText = $body;

        $this->em->flush();

        $result = $this->method->handle(
            [
                'accountId' => $this->accountId(),
                'emailIds'  => [(string) $message->id],
                'filter'    => ['text' => 'chargecloud'],
            ],
            $this->context(),
        );

        /** @var list<array{emailId: string, subject: ?string, preview: ?string}> $list */
        $list = $result['list'];

        self::assertCount(1, $list, 'the message seeded above should be the one snippet');

        return $list[0];
    }
}
