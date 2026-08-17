<?php

declare(strict_types=1);

namespace App\Tests\Service\Insight\Extractor;

use App\Domain\Enum\Insight\InsightKind;
use App\Entity\Mail\Message;
use App\Service\Insight\Extractor\GithubExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the GitHub extractor makes of real notification shapes, and what it
 * refuses to.
 *
 * Table-driven because the subject is a table: each row is one mail GitHub
 * actually sends — a review request, a mention, a merge notice, a digest —
 * and the interesting rows are the refusals, where several links or none
 * mean there is no ONE thing to make a card of.
 *
 * A plain TestCase and Messages built in memory, because an extractor is a
 * pure function of a Message — that purity is the whole reason InsightDraft
 * is a DTO and not an entity.
 */
final class GithubExtractorTest extends TestCase
{
    private GithubExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new GithubExtractor();
    }

    /**
     * @param array{kind: InsightKind, title: string, dedupeKey: string, payload: array<string, mixed>}|null $expected
     */
    #[DataProvider('notifications')]
    public function testItMakesOneCardOfTheThingTheMailIsAbout(Message $message, ?array $expected): void
    {
        self::assertTrue($this->extractor->supports($message), 'every fixture here is a GitHub mail');

        $drafts = $this->extractor->extract($message);

        if (null === $expected) {
            self::assertSame([], $drafts, 'no single clear thread means no card');

            return;
        }

        self::assertCount(1, $drafts, 'one mail about one thread is one draft');

        $draft = $drafts[0];

        self::assertSame($expected['kind'], $draft->kind);
        self::assertSame($expected['title'], $draft->title);
        self::assertSame($expected['dedupeKey'], $draft->dedupeKey);
        self::assertSame($expected['payload'], $draft->payload);
        self::assertNull($draft->happensAt, 'a thread has no occurrence on a timeline');
    }

    /**
     * @return array<string, array{Message, array{kind: InsightKind, title: string, dedupeKey: string, payload: array<string, mixed>}|null}>
     */
    public static function notifications(): array
    {
        return [
            'a review request is a pull-request card' => [
                self::mail(
                    subject: '[acme/widget] Fix null deref in the header parser (#482)',
                    body: <<<'MAIL'
                        @ada-lovelace requested your review on: #482 Fix null deref in the header parser.

                        View it on GitHub:
                        https://github.com/acme/widget/pull/482

                        --
                        You are receiving this because your review was requested.
                        MAIL,
                    fromName: 'Ada Lovelace',
                    headers: [
                        'list-id' => 'acme/widget <widget.acme.github.com>',
                        'x-github-reason' => 'review_requested',
                    ],
                ),
                [
                    'kind' => InsightKind::GithubPullRequest,
                    'title' => 'acme/widget #482 — Fix null deref in the header parser',
                    'dedupeKey' => 'acme/widget#482',
                    'payload' => [
                        'repo' => 'acme/widget',
                        'number' => 482,
                        'action' => 'review_requested',
                        'url' => 'https://github.com/acme/widget/pull/482',
                        'author' => 'Ada Lovelace',
                    ],
                ],
            ],
            'a mention on an issue, Re: prefix and comment fragment included' => [
                self::mail(
                    subject: 'Re: [acme/widget] Sync loops forever when List-Id repeats (#17)',
                    body: <<<'MAIL'
                        @karatektus can you reproduce this on 0.0.38?

                        --
                        Reply to this email directly or view it on GitHub:
                        https://github.com/acme/widget/issues/17#issuecomment-1830022
                        You are receiving this because you were mentioned.
                        MAIL,
                    fromName: 'Grace Hopper',
                    headers: [
                        'list-id' => 'acme/widget <widget.acme.github.com>',
                        'x-github-reason' => 'mention',
                    ],
                ),
                [
                    'kind' => InsightKind::GithubIssue,
                    'title' => 'acme/widget #17 — Sync loops forever when List-Id repeats',
                    'dedupeKey' => 'acme/widget#17',
                    'payload' => [
                        'repo' => 'acme/widget',
                        'number' => 17,
                        'action' => 'mention',
                        'url' => 'https://github.com/acme/widget/issues/17',
                        'author' => 'Grace Hopper',
                    ],
                ],
            ],
            'a merge notice: the body verdict beats the reason header, and "GitHub" is nobody' => [
                self::mail(
                    subject: '[acme/widget] Fix null deref in the header parser (#482)',
                    body: <<<'MAIL'
                        Merged #482 into main.

                        --
                        View it on GitHub:
                        https://github.com/acme/widget/pull/482
                        You are receiving this because you are subscribed to this repository.
                        MAIL,
                    fromName: 'GitHub',
                    headers: [
                        'list-id' => 'acme/widget <widget.acme.github.com>',
                        'x-github-reason' => 'subscribed',
                    ],
                ),
                [
                    'kind' => InsightKind::GithubPullRequest,
                    'title' => 'acme/widget #482 — Fix null deref in the header parser',
                    'dedupeKey' => 'acme/widget#482',
                    'payload' => [
                        'repo' => 'acme/widget',
                        'number' => 482,
                        'action' => 'merged',
                        'url' => 'https://github.com/acme/widget/pull/482',
                        'author' => null,
                    ],
                ],
            ],
            'a closed issue reads its verdict from the opening line too' => [
                self::mail(
                    subject: '[acme/widget] Sync loops forever when List-Id repeats (#17)',
                    body: <<<'MAIL'
                        Closed #17 as completed.

                        --
                        View it on GitHub:
                        https://github.com/acme/widget/issues/17
                        MAIL,
                    fromName: 'Ada Lovelace',
                    headers: ['x-github-reason' => 'author'],
                ),
                [
                    'kind' => InsightKind::GithubIssue,
                    'title' => 'acme/widget #17 — Sync loops forever when List-Id repeats',
                    'dedupeKey' => 'acme/widget#17',
                    'payload' => [
                        'repo' => 'acme/widget',
                        'number' => 17,
                        'action' => 'closed',
                        'url' => 'https://github.com/acme/widget/issues/17',
                        'author' => 'Ada Lovelace',
                    ],
                ],
            ],
            'a Dependabot digest names many pull requests and therefore none' => [
                self::mail(
                    subject: 'Your Dependabot updates for acme/widget',
                    body: <<<'MAIL'
                        Dependabot has opened 2 pull requests:

                        https://github.com/acme/widget/pull/501 Bump symfony/console from 7.1 to 7.2
                        https://github.com/acme/widget/pull/502 Bump phpunit/phpunit from 11 to 12
                        MAIL,
                    fromName: 'GitHub',
                ),
                null,
            ],
            'a CI failure mail names a repo but no thread number' => [
                self::mail(
                    subject: '[acme/widget] Run failed: CI - main (a1b2c3d)',
                    body: <<<'MAIL'
                        CI workflow run failed for main.

                        View workflow run:
                        https://github.com/acme/widget/actions/runs/9912873
                        MAIL,
                    fromName: 'GitHub',
                ),
                null,
            ],
        ];
    }

    public function testItIgnoresMailFromAnywhereElse(): void
    {
        $message = self::mail(
            subject: 'Your weekly newsletter (#42)',
            body: 'Nothing about code in here.',
            from: 'news@example.com',
        );

        self::assertFalse($this->extractor->supports($message));
    }

    public function testTheListIdCarriesSupportWhenTheSenderWasRewritten(): void
    {
        // A corporate relay rewrote the envelope; the List-Id survived, and
        // it survived as a repeated header, so the bag holds a list.
        $message = self::mail(
            subject: '[acme/widget] Fix null deref in the header parser (#482)',
            body: 'https://github.com/acme/widget/pull/482',
            from: 'relay@corp.example',
            headers: ['list-id' => ['acme/widget <widget.acme.github.com>']],
        );

        self::assertTrue($this->extractor->supports($message));
    }

    public function testItsRegistryIdentity(): void
    {
        self::assertSame('github', GithubExtractor::key());
        self::assertSame('fa-brands fa-github', $this->extractor->icon());
        self::assertSame(120, $this->extractor->priority());
    }

    /**
     * @param array<string, string|list<string>>|null $headers
     */
    private static function mail(
        string $subject,
        string $body,
        string $from = 'notifications@github.com',
        ?string $fromName = null,
        ?array $headers = null,
    ): Message {
        $message = new Message();
        $message->subject = $subject;
        $message->bodyText = $body;
        $message->fromAddress = $from;
        $message->fromName = $fromName;
        $message->headers = $headers;

        return $message;
    }
}
