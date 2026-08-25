<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Job\JobKind;
use App\Repository\Job\BackgroundJobRepository;
use App\Repository\User\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A whole-view bulk action is handed to a worker, not done in the request.
 *
 * WHY THIS IS NOT AN E2E TEST
 *
 * It was one, and it was a bad citizen. A view selection spans every account by
 * construction — the unified inbox is the point of it — so acting on one in the
 * shared fixture mailbox reaches every other spec's mail, and the job's timing
 * is the test environment's to decide, not the spec's. Two suites' worth of
 * unrelated failures came from that.
 *
 * What actually needs pinning is the contract: the request creates a job and
 * returns, rather than hydrating five thousand threads inside thirty seconds.
 * That is a controller-level fact and belongs where the fixture is controlled.
 */
final class BulkStatusOffloadTest extends WebTestCase
{
    private const string USER_EMAIL = 'e2e@plmail.test';

    /**
     * The request answers without doing the work.
     *
     * Previously it resolved the view, hydrated every thread and every message,
     * checked ownership per thread and wrote — inside a request with thirty
     * seconds to live. On a mailbox with five thousand unread that is
     * `Maximum execution time of 30 seconds exceeded`, and the user cannot tell
     * how much of it happened.
     */
    public function testAWholeViewActionCreatesAJobAndReturns(): void
    {
        $client = $this->signedIn();

        $before = $this->jobs()->count([]);

        $this->post($client, 'read', [
            'all'        => true,
            'scope'      => 'inbox',
            'value'      => 'primary',
            'unreadOnly' => true,
            'read'       => true,
        ]);

        self::assertResponseIsSuccessful();

        // Counted against the baseline rather than asserted as one: this table
        // is shared with whatever else the suite has run.
        self::assertSame($before + 1, $this->jobs()->count([]), 'no job was created for a whole-view action');

        $job = $this->jobs()->findOneBy([], ['id' => 'DESC']);

        self::assertNotNull($job);
        self::assertSame(JobKind::MarkRead, $job->kind);

        // Deliberately NOT asserting that it is still running. This
        // environment's transport handles a dispatch inline, so by the time the
        // response comes back the job has already finished — which is also what
        // made the E2E version of this test corrupt other specs. What is being
        // pinned is the contract the controller owns: a job exists, describing
        // the right work. When it runs is the transport's business, and in
        // production that is a worker.

        // The selection travels on the job, not in the envelope: a queue row
        // should not be the size of the work it describes.
        self::assertSame('inbox', $job->view['scope']);
        self::assertSame('primary', $job->view['value']);
        self::assertTrue($job->view['unreadOnly']);
    }

    /** Marking unread is the same action with the flag the other way. */
    public function testTheKindFollowsTheRequestedDirection(): void
    {
        $client = $this->signedIn();

        $this->post($client, 'read', [
            'all'   => true,
            'scope' => 'inbox',
            'value' => 'primary',
            'read'  => false,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(JobKind::MarkUnread, $this->jobs()->findOneBy([], ['id' => 'DESC'])?->kind);
    }

    /**
     * An explicit list of ids stays inline, and that is deliberate.
     *
     * It is bounded by the rows on screen, it finishes in milliseconds, and
     * answering it with "started" instead of the result would make every
     * ordinary archive feel slower than it is.
     */
    public function testAListOfIdsDoesNotCreateAJob(): void
    {
        $client = $this->signedIn();

        $before = $this->jobs()->count([]);

        $this->post($client, 'read', ['ids' => [], 'read' => true]);

        self::assertResponseIsSuccessful();
        self::assertSame($before, $this->jobs()->count([]), 'a page-sized selection was pushed to a worker');
    }

    /** @param array<string, mixed> $body */
    private function post(KernelBrowser $client, string $action, array $body): void
    {
        $crawler = $client->request('GET', '/mail/inbox');
        $token   = (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');

        $client->request(
            'POST',
            '/status/bulk/' . $action,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            (string) json_encode($body),
        );
    }

    private function jobs(): BackgroundJobRepository
    {
        return static::getContainer()->get(BackgroundJobRepository::class);
    }

    private function signedIn(): KernelBrowser
    {
        $client = static::createClient();

        $user = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => self::USER_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user` first');
        }

        $client->loginUser($user);

        return $client;
    }
}
