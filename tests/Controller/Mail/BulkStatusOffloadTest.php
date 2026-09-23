<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Job\JobKind;
use App\Infrastructure\Messaging\Message\RunBulkStatusMessage;
use App\Repository\Job\BackgroundJobRepository;
use App\Repository\User\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;

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

        // Deliberately NOT asserting that it is still running: when it runs
        // is the transport's business, and asserting on timing here is what
        // made the E2E version of this test corrupt other specs. What is
        // pinned is the contract the controller owns — a job exists, describing
        // the right work.
        //
        // That the transport is a WORKER and not this very request is the next
        // test. It used to be a sentence of prose here saying "in production
        // that is a worker", and that sentence was wrong for as long as it had
        // been written.

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
     * Bulk snooze is a route, and its wake time travels on the job.
     *
     * The toolbar's snooze menu posted to /status/bulk/snooze for as long as it
     * existed and the route's allow-list never named it: every bulk snooze was
     * a 404 and a toast. An empty explicit list is the cheapest proof the
     * route answers; the whole view is the path with a payload to lose.
     */
    public function testBulkSnoozeIsAcceptedAndCarriesItsWakeTime(): void
    {
        $client = $this->signedIn();

        $this->post($client, 'snooze', ['ids' => [], 'until' => '2030-01-02T08:00:00+00:00']);
        self::assertResponseIsSuccessful();

        // A label that does not exist, so a worker picking the job up in the
        // test stack finds nothing to snooze: the fixture inbox put away until
        // 2030 would be every other spec's problem.
        $this->post($client, 'snooze', [
            'all'   => true,
            'scope' => 'label',
            'value' => '999999999',
            'until' => '2030-01-02T08:00:00+00:00',
        ]);
        self::assertResponseIsSuccessful();

        $job = $this->jobs()->findOneBy([], ['id' => 'DESC']);
        self::assertSame(JobKind::Snooze, $job?->kind);
        self::assertSame('2030-01-02T08:00:00+00:00', $job->view['until']);

        // No wake time is "wake these", which the indicator must not call snoozing.
        $this->post($client, 'snooze', ['all' => true, 'scope' => 'label', 'value' => '999999999', 'until' => null]);
        self::assertResponseIsSuccessful();
        self::assertSame(JobKind::Wake, $this->jobs()->findOneBy([], ['id' => 'DESC'])?->kind);
    }

    /**
     * The envelope reaches a queue instead of being run on the spot.
     *
     * THIS IS THE TEST THAT WAS MISSING, and the prose above carried the gap as
     * an assumption. Nothing routed RunBulkStatusMessage in
     * config/packages/messenger.yaml, and Messenger handles an unrouted message
     * INLINE in whichever process dispatched it — so every whole-view action
     * ran inside the web request, under exactly the time limit
     * RunBulkStatusHandler was written to escape, and at request concurrency,
     * which is where the production deadlock came from. That file warns about
     * this precise trap one routing entry above, for RunCommandMessage.
     *
     * The queue is asserted BY NAME, not "some transport got something". Which
     * one it lands on is half the decision: `maintenance` would park a bulk
     * action behind an embedding backfill, and `export` would park somebody's
     * send behind the bulk action.
     *
     * ASKED OF THE ROUTING, not of a transport's contents, and that is not
     * squeamishness about side effects. `bulk` is the one queue whose DSN can
     * be overridden per environment — compose.test.yaml points it at Postgres
     * and runs a worker, so the browser suite's bulk actions really happen —
     * and a test that reached for InMemoryTransport::getSent() therefore
     * passed on a developer's machine and died with a TypeError in the
     * container the suite actually runs in. What is being pinned is which
     * queue the envelope is addressed to, and SendersLocator answers that
     * whatever is at the other end.
     */
    public function testTheJobIsHandedToTheBulkQueue(): void
    {
        $senders = static::getContainer()
            ->get('messenger.senders_locator')
            ->getSenders(new Envelope(new RunBulkStatusMessage(1)));

        self::assertSame(
            ['bulk'],
            array_keys(iterator_to_array($senders)),
            'a whole-view action is not addressed to the bulk queue',
        );
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
