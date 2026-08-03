<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Mail;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Entity\Mail\EmailAlias;
use App\Entity\Mail\Message;
use App\Infrastructure\Messaging\Message\SendMessageMessage;
use App\Jmap\Method\Mail\EmailSubmissionGetMethod;
use App\Jmap\Method\Mail\EmailSubmissionSetMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Tests\Jmap\JmapTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * "EmailSubmission/set" — sending.
 *
 * There is no submission table: a submission id IS the Email id, which works
 * only because plMail sends each draft at most once. The method's real job is
 * to decide what may be handed to the send pipeline, and the refusals below are
 * the whole of that decision — a draft with no recipients, or one already sent,
 * must not reach the bus.
 *
 * The gap pinned here is `identityId`, which this method ignores. That is
 * documented as an open ask rather than a product rule: the web composer
 * honours the chosen alias and JMAP does not, so a client offering an alias
 * picker would be lying to the user about which address the mail leaves as.
 */
final class EmailSubmissionSetMethodTest extends JmapTestCase
{
    private EmailSubmissionSetMethod $method;
    private EmailSubmissionGetMethod $get;
    private InMemoryTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $container = self::getContainer();
        $this->method = $container->get(EmailSubmissionSetMethod::class);
        $this->get    = $container->get(EmailSubmissionGetMethod::class);

        $transport = $container->get('messenger.transport.async');

        self::assertInstanceOf(InMemoryTransport::class, $transport, 'the test transport must not really send mail');

        $this->transport = $transport;
        $this->transport->reset();
    }

    public function testItIsNamedEmailSubmissionSet(): void
    {
        self::assertSame('EmailSubmission/set', $this->method->name());
    }

    // ── what reaches the send pipeline ────────────────────────────────────

    /**
     * Sending is delegated to the same SendMessageMessage pipeline the web
     * composer's send button uses, so the draft-to-sent transition happens in
     * one place regardless of which surface asked for it.
     */
    public function testASubmissionQueuesTheDraftOnTheSendPipeline(): void
    {
        $draft = $this->addressedDraft();

        $result = $this->handle(['create' => ['s1' => ['emailId' => (string) $draft->id]]]);

        self::assertArrayHasKey('s1', (array) $result['created']);
        self::assertSame([(int) $draft->id], $this->queuedMessageIds());
    }

    /**
     * "pending" is the truth: the send is queued and has genuinely not
     * happened when this returns. A client polls EmailSubmission/get for the
     * transition rather than believing the create.
     */
    public function testTheSubmissionIsReportedAsPending(): void
    {
        $draft = $this->addressedDraft();

        $created = ((array) $this->handle(['create' => ['s1' => ['emailId' => (string) $draft->id]]])['created'])['s1'];

        self::assertSame('pending', $created['undoStatus']);
        self::assertSame((string) $draft->id, $created['id'], 'a submission id is the Email id');
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $created['sendAt']);
    }

    /** The creation-id form works here, which is how a compose-and-send in one request is written. */
    public function testADraftCreatedEarlierInTheRequestCanBeSubmittedByCreationId(): void
    {
        $draft = $this->addressedDraft();
        $context = $this->context();
        $context->recordCreatedId('d1', (string) $draft->id);

        $result = $this->method->handle(
            ['accountId' => $this->accountId(), 'create' => ['s1' => ['emailId' => '#d1']]],
            $context,
        );

        self::assertArrayHasKey('s1', (array) $result['created']);
        self::assertSame([(int) $draft->id], $this->queuedMessageIds());
    }

    public function testAnUnknownCreationIdIsRefused(): void
    {
        $result = $this->handle(['create' => ['s1' => ['emailId' => '#never-created']]]);

        $error = ((array) $result['notCreated'])['s1'];

        self::assertSame('invalidProperties', $error['type']);
        self::assertStringContainsString('Unknown creation id', $error['description']);
        self::assertSame([], $this->queuedMessageIds());
    }

    // ── the refusals ──────────────────────────────────────────────────────

    /**
     * A message with no To, Cc or Bcc has nowhere to go. Queued, it would fail
     * inside a worker where the user never sees it.
     */
    public function testADraftWithNoRecipientsIsRefusedWithNoRecipients(): void
    {
        $draft = $this->draftMessage();

        $result = $this->handle(['create' => ['s1' => ['emailId' => (string) $draft->id]]]);

        self::assertSame('noRecipients', ((array) $result['notCreated'])['s1']['type']);
        self::assertSame([], $this->queuedMessageIds());
    }

    /**
     * The one-to-one Email-to-submission mapping only holds because a draft
     * sends once. A second submission would mint a duplicate id for an object
     * that already exists — hence alreadyExists rather than a silent re-send.
     */
    public function testAnAlreadySentEmailCannotBeSubmittedAgain(): void
    {
        $draft = $this->addressedDraft();
        $draft->sentAt = new \DateTimeImmutable('-1 minute');
        $this->em->flush();

        $result = $this->handle(['create' => ['s1' => ['emailId' => (string) $draft->id]]]);

        self::assertSame('alreadyExists', ((array) $result['notCreated'])['s1']['type']);
        self::assertSame([], $this->queuedMessageIds());
    }

    public function testAMissingEmailIdIsRefused(): void
    {
        $result = $this->handle(['create' => ['s1' => ['identityId' => '1']]]);

        self::assertSame('invalidProperties', ((array) $result['notCreated'])['s1']['type']);
    }

    public function testAnEmailOutsideTheAccountIsRefused(): void
    {
        $result = $this->handle(['create' => ['s1' => ['emailId' => '999999']]]);

        $error = ((array) $result['notCreated'])['s1'];

        self::assertSame('invalidProperties', $error['type']);
        self::assertStringContainsString('No such Email', $error['description']);
    }

    public function testANonObjectCreateArgumentFailsTheWholeCall(): void
    {
        $this->expectException(MethodException::class);

        $this->handle(['create' => 'not a map']);
    }

    // ── the identityId gap ────────────────────────────────────────────────

    /**
     * A DOCUMENTED GAP, pinned as it currently behaves.
     *
     * identityId is read by nothing: the message goes out as the account's own
     * address whichever alias the client names, and no error says otherwise.
     * The web composer honours the choice through
     * ComposeController::resolveFromAddress(), so this is a JMAP-only omission
     * rather than a product decision — which is why the Android client shows
     * one From entry per account rather than one per alias.
     *
     * Pinned rather than fixed because honouring it is a production change.
     * WHEN identityId IS HONOURED, THIS TEST MUST FAIL.
     */
    public function testTheChosenIdentityDoesNotReachTheSubmittedMessage(): void
    {
        $alias = new EmailAlias(
            $this->account,
            'other-me@example.test',
            EmailAliasSource::Manual,
            EmailAliasStatus::Active,
        );
        $this->account->addAlias($alias);
        $this->em->persist($alias);
        $this->em->flush();

        $draft = $this->addressedDraft();
        $draft->fromAddress = (string) $this->account->email;
        $this->em->flush();

        $result = $this->handle([
            'create' => ['s1' => ['emailId' => (string) $draft->id, 'identityId' => (string) $alias->id]],
        ]);

        self::assertArrayHasKey('s1', (array) $result['created'], 'an unknown identity is not even refused');
        self::assertSame($this->account->email, $draft->fromAddress);
    }

    // ── onSuccessUpdateEmail ──────────────────────────────────────────────

    /**
     * The spec has the server report the implicit Email/set it performed, so
     * the client does not have to re-fetch what it just asked for.
     */
    public function testOnSuccessUpdateEmailIsAppliedAndReported(): void
    {
        $draft = $this->addressedDraft();

        $result = $this->handle([
            'create' => ['s1' => ['emailId' => (string) $draft->id]],
            'onSuccessUpdateEmail' => ['#s1' => ['keywords/$seen' => true]],
        ]);

        self::assertSame([(string) $draft->id => null], $result['updatedEmails']);
        self::assertNotNull($draft->seenAt);
    }

    /** Conditional on success: a refused submission must not patch its Email. */
    public function testOnSuccessUpdateEmailDoesNotRunForARefusedSubmission(): void
    {
        $draft = $this->draftMessage();

        $result = $this->handle([
            'create' => ['s1' => ['emailId' => (string) $draft->id]],
            'onSuccessUpdateEmail' => ['#s1' => ['keywords/$seen' => true]],
        ]);

        self::assertArrayNotHasKey('updatedEmails', $result);
        self::assertNull($draft->seenAt);
    }

    /** The key is absent rather than empty when there was nothing to report. */
    public function testUpdatedEmailsIsOmittedWhenNoPatchWasAskedFor(): void
    {
        $draft = $this->addressedDraft();

        $result = $this->handle(['create' => ['s1' => ['emailId' => (string) $draft->id]]]);

        self::assertArrayNotHasKey('updatedEmails', $result);
    }

    // ── EmailSubmission/get reconstructs from the Message ─────────────────

    /**
     * A draft that was never sent is notFound, which is exactly what a client
     * polling undoStatus needs: "not yet" and "no such thing" are the same
     * answer here because there is no submission row to be in either state.
     */
    public function testAnUnsentDraftHasNoSubmissionToGet(): void
    {
        $draft = $this->addressedDraft();

        $this->handle(['create' => ['s1' => ['emailId' => (string) $draft->id]]]);

        $result = $this->get->handle(
            ['accountId' => $this->accountId(), 'ids' => [(string) $draft->id]],
            $this->context(),
        );

        self::assertSame([], $result['list']);
        self::assertSame([(string) $draft->id], $result['notFound']);
    }

    public function testASentEmailReconstructsIntoAFinalSubmission(): void
    {
        $draft = $this->addressedDraft();
        $draft->sentAt = new \DateTimeImmutable('2026-01-02T03:04:05Z');
        $this->em->flush();

        $result = $this->get->handle(
            ['accountId' => $this->accountId(), 'ids' => [(string) $draft->id]],
            $this->context(),
        );

        $submission = $result['list'][0];

        self::assertSame((string) $draft->id, $submission['emailId']);
        self::assertSame('final', $submission['undoStatus']);
        self::assertSame('2026-01-02T03:04:05Z', $submission['sendAt']);
        self::assertSame(
            [['email' => 'recipient@example.test', 'parameters' => null]],
            $submission['envelope']['rcptTo'],
        );
    }

    /**
     * There is no submission table to enumerate, so "give me everything" has
     * no answer and asking for one is refused rather than silently returning
     * an empty list.
     */
    public function testEmailSubmissionGetRequiresIds(): void
    {
        $this->expectException(MethodException::class);

        $this->get->handle(['accountId' => $this->accountId()], $this->context());
    }

    public function testEmailSubmissionGetRefusesMoreIdsThanItWillAnswer(): void
    {
        $this->expectException(MethodException::class);

        $this->get->handle(
            ['accountId' => $this->accountId(), 'ids' => array_fill(0, 501, '1')],
            $this->context(),
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $arguments
     *
     * @return array<string,mixed>
     */
    private function handle(array $arguments): array
    {
        return $this->method->handle(
            $arguments + ['accountId' => $this->accountId()],
            $this->context(),
        );
    }

    /** @return list<int> */
    private function queuedMessageIds(): array
    {
        $ids = [];

        foreach ($this->transport->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof SendMessageMessage) {
                $ids[] = $message->messageId;
            }
        }

        return $ids;
    }

    private function addressedDraft(): Message
    {
        $draft = $this->draftMessage();
        $draft->toAddresses = [['name' => null, 'address' => 'recipient@example.test']];
        $this->em->flush();

        return $draft;
    }
}
