<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Mail;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Entity\Mail\Message;
use App\Infrastructure\Messaging\Message\SendMessageMessage;
use App\Jmap\Mail\SubmissionEnvelope;
use App\Jmap\Method\Mail\EmailSubmissionGetMethod;
use App\Jmap\Method\Mail\EmailSubmissionSetMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Tests\Jmap\JmapTestCase;
use Symfony\Component\Messenger\Stamp\DelayStamp;
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
 * Three client decisions are checked here beyond that, because each of them
 * changes mail that leaves the building: the identity it is sent as, the time
 * it is released, and a cancel before that time. Every one of them is refused
 * by name when it cannot be honoured — an identity that resolves to nothing
 * must never quietly become the account's own address, and a hold longer than
 * the session advertises must never quietly become a send now.
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

        $transport = $container->get('messenger.transport.export');

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

    // ── identityId: which address the mail leaves as ──────────────────────

    /**
     * The point of the property. MessageSendService reads From off the row, so
     * writing it here is what actually changes the header — and the identity
     * ids accepted are exactly the ones Identity/get published, because both
     * go through IdentityResolver.
     */
    public function testTheChosenIdentityBecomesTheFromAddress(): void
    {
        $alias = $this->alias('other-me@example.test');
        $draft = $this->addressedDraft();
        $draft->fromAddress = (string) $this->account->email;
        $this->em->flush();

        $result = $this->handle([
            'create' => ['s1' => ['emailId' => (string) $draft->id, 'identityId' => (string) $alias->id]],
        ]);

        self::assertArrayHasKey('s1', (array) $result['created']);
        self::assertSame('other-me@example.test', $draft->fromAddress);
        self::assertSame([(int) $draft->id], $this->queuedMessageIds());
    }

    /**
     * An account with no alias rows yet publishes one synthetic identity for
     * its own address, keyed by the account id — a client that names it is
     * naming something Identity/get offered, so it resolves.
     */
    public function testTheSyntheticAccountIdentityResolvesWhileThereAreNoAliases(): void
    {
        $draft = $this->addressedDraft();

        $result = $this->handle([
            'create' => ['s1' => ['emailId' => (string) $draft->id, 'identityId' => $this->accountId()]],
        ]);

        self::assertArrayHasKey('s1', (array) $result['created']);
        self::assertSame($this->account->email, $draft->fromAddress);
    }

    /**
     * The refusal this whole feature exists for. Sending it as the account's
     * own address instead would put a different address on the wire than the
     * user picked, and report success.
     */
    public function testAnIdentityThatResolvesToNothingIsRefusedRatherThanSubstituted(): void
    {
        $draft = $this->addressedDraft();
        $draft->fromAddress = (string) $this->account->email;
        $this->em->flush();

        $result = $this->handle([
            'create' => ['s1' => ['emailId' => (string) $draft->id, 'identityId' => '404404']],
        ]);

        $error = ((array) $result['notCreated'])['s1'];

        self::assertSame('forbiddenFrom', $error['type']);
        self::assertStringContainsString('Identity/get', $error['description']);
        self::assertSame($this->account->email, $draft->fromAddress, 'a refused submission must not rewrite the From');
        self::assertSame([], $this->queuedMessageIds(), 'and must not reach the bus');
    }

    /** An alias is an address of one account. Another account's is not this account's. */
    public function testAnIdentityBelongingToAnotherAccountIsRefused(): void
    {
        $other = $this->secondAccount();
        $alias = $this->alias('elsewhere@example.test', $other);
        $draft = $this->addressedDraft();

        $result = $this->handle([
            'create' => ['s1' => ['emailId' => (string) $draft->id, 'identityId' => (string) $alias->id]],
        ]);

        self::assertSame('forbiddenFrom', ((array) $result['notCreated'])['s1']['type']);
    }

    /**
     * No identityId is the case every existing client is in, and the case the
     * web composer leaves behind: it resolved the alias when it saved the
     * draft, so the address on the row is already the user's choice and this
     * method must not touch it.
     */
    public function testASubmissionWithoutAnIdentityLeavesTheFromAddressAlone(): void
    {
        $this->alias('other-me@example.test');

        $draft = $this->addressedDraft();
        $draft->fromAddress = 'other-me@example.test';
        $this->em->flush();

        $this->handle(['create' => ['s1' => ['emailId' => (string) $draft->id]]]);

        self::assertSame('other-me@example.test', $draft->fromAddress);
    }

    /** EmailSubmission/get names the identity the mail really left as, not the accountId. */
    public function testASentSubmissionReportsTheIdentityItWasSentAs(): void
    {
        $alias = $this->alias('other-me@example.test');

        $draft = $this->addressedDraft();
        $draft->fromAddress = 'other-me@example.test';
        $draft->sentAt = new \DateTimeImmutable('-1 minute');
        $this->em->flush();

        $result = $this->get->handle(
            ['accountId' => $this->accountId(), 'ids' => [(string) $draft->id]],
            $this->context(),
        );

        self::assertSame((string) $alias->id, $result['list'][0]['identityId']);
    }

    // ── scheduled send (FUTURERELEASE) ────────────────────────────────────

    /**
     * RFC 8621 §7 carries the request as RFC 4865 envelope parameters rather
     * than inventing a property, and plMail honours it by holding the
     * messenger envelope. Both halves are asserted: the delay the bus is given
     * and the sendAt the client is told, which have to describe the same
     * instant or the client's "sending in an hour" is a guess.
     */
    public function testAHoldForDelaysTheEnvelopeAndTheReportedSendAt(): void
    {
        $draft = $this->addressedDraft();
        $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $created = ((array) $this->handle([
            'create' => ['s1' => [
                'emailId' => (string) $draft->id,
                'envelope' => ['mailFrom' => ['parameters' => ['HOLDFOR' => '3600']]],
            ]],
        ])['created'])['s1'];

        self::assertSame([3_600_000], $this->queuedDelays());
        self::assertSame('pending', $created['undoStatus']);

        $sendAt = new \DateTimeImmutable($created['sendAt']);

        self::assertGreaterThanOrEqual(3_599, $sendAt->getTimestamp() - $before->getTimestamp());
        self::assertLessThanOrEqual(3_610, $sendAt->getTimestamp() - $before->getTimestamp());
    }

    /** The other spelling of the same request: an instant rather than a duration. */
    public function testAHoldUntilDelaysToThatInstant(): void
    {
        $draft = $this->addressedDraft();
        $until = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+2 hours');

        $created = ((array) $this->handle([
            'create' => ['s1' => [
                'emailId' => (string) $draft->id,
                'envelope' => ['mailFrom' => ['parameters' => ['HOLDUNTIL' => $until->format('Y-m-d\TH:i:s\Z')]]],
            ]],
        ])['created'])['s1'];

        self::assertSame($until->format('Y-m-d\TH:i:s\Z'), $created['sendAt']);
        self::assertEqualsWithDelta(7_200_000, $this->queuedDelays()[0], 10_000);
    }

    /**
     * Parameter names are ESMTP keywords, which RFC 5321 makes case
     * insensitive — a client sending "holdFor" means the same thing.
     */
    public function testTheHoldParameterNameIsCaseInsensitive(): void
    {
        $draft = $this->addressedDraft();

        $this->handle([
            'create' => ['s1' => [
                'emailId' => (string) $draft->id,
                'envelope' => ['mailFrom' => ['parameters' => ['holdFor' => 60]]],
            ]],
        ]);

        self::assertSame([60_000], $this->queuedDelays());
    }

    /** The immediate path, unchanged: no stamp at all rather than a zero one. */
    public function testASubmissionWithNoHoldIsDispatchedWithoutADelayStamp(): void
    {
        $draft = $this->addressedDraft();

        $this->handle(['create' => ['s1' => ['emailId' => (string) $draft->id]]]);

        self::assertSame([null], $this->queuedDelays());
    }

    /** RFC 4865: a release time that has passed is a send now, not an error. */
    public function testAHoldThatHasAlreadyElapsedSendsImmediately(): void
    {
        $draft = $this->addressedDraft();

        $this->handle([
            'create' => ['s1' => [
                'emailId' => (string) $draft->id,
                'envelope' => ['mailFrom' => ['parameters' => ['HOLDUNTIL' => '2020-01-01T00:00:00Z']]],
            ]],
        ]);

        self::assertSame([null], $this->queuedDelays());
    }

    /**
     * maxDelayedSend is a promise, so the ceiling is enforced rather than
     * clamped: a client asking for a year must not be told "pending" for a
     * message that leaves in thirty days.
     */
    public function testAHoldBeyondTheAdvertisedMaximumIsRefused(): void
    {
        $draft = $this->addressedDraft();

        $result = $this->handle([
            'create' => ['s1' => [
                'emailId' => (string) $draft->id,
                'envelope' => ['mailFrom' => ['parameters' => ['HOLDFOR' => (string) (SubmissionEnvelope::MAX_HOLD_SECONDS + 1)]]],
            ]],
        ]);

        $error = ((array) $result['notCreated'])['s1'];

        self::assertSame('invalidProperties', $error['type']);
        self::assertStringContainsString('maxDelayedSend', $error['description']);
        self::assertSame([], $this->queuedMessageIds());
    }

    public function testAHoldUntilBeyondTheMaximumIsRefused(): void
    {
        $draft = $this->addressedDraft();

        $result = $this->handle([
            'create' => ['s1' => [
                'emailId' => (string) $draft->id,
                'envelope' => ['mailFrom' => ['parameters' => ['HOLDUNTIL' => (new \DateTimeImmutable('+2 years'))->format('Y-m-d\TH:i:s\Z')]]],
            ]],
        ]);

        self::assertSame('invalidProperties', ((array) $result['notCreated'])['s1']['type']);
    }

    /** Two answers to "when does this leave" is a disagreement, not a default. */
    public function testHoldForAndHoldUntilTogetherAreRefused(): void
    {
        $draft = $this->addressedDraft();

        $result = $this->handle([
            'create' => ['s1' => [
                'emailId' => (string) $draft->id,
                'envelope' => ['mailFrom' => ['parameters' => ['HOLDFOR' => '60', 'HOLDUNTIL' => '2099-01-01T00:00:00Z']]],
            ]],
        ]);

        self::assertSame('invalidProperties', ((array) $result['notCreated'])['s1']['type']);
        self::assertSame([], $this->queuedMessageIds());
    }

    /** Nothing else in the envelope is supported, and unsupported is said out loud. */
    public function testAnUnknownEnvelopeParameterIsRefusedByName(): void
    {
        $draft = $this->addressedDraft();

        $result = $this->handle([
            'create' => ['s1' => [
                'emailId' => (string) $draft->id,
                'envelope' => ['mailFrom' => ['parameters' => ['RET' => 'FULL']]],
            ]],
        ]);

        $error = ((array) $result['notCreated'])['s1'];

        self::assertSame('invalidProperties', $error['type']);
        self::assertStringContainsString('HOLDFOR', $error['description']);
    }

    /**
     * The envelope describes the mail this server will send. It sends From the
     * row, so an envelope naming another sender describes something else —
     * refused rather than accepted and ignored.
     */
    public function testAnEnvelopeSenderThatIsNotTheSubmissionAddressIsRefused(): void
    {
        $draft = $this->addressedDraft();

        $result = $this->handle([
            'create' => ['s1' => [
                'emailId' => (string) $draft->id,
                'envelope' => ['mailFrom' => ['email' => 'someone-else@example.test']],
            ]],
        ]);

        self::assertSame('forbiddenFrom', ((array) $result['notCreated'])['s1']['type']);
        self::assertSame([], $this->queuedMessageIds());
    }

    /** And it is compared against the identity just chosen, not the account address. */
    public function testAnEnvelopeSenderMatchingTheChosenIdentityIsAccepted(): void
    {
        $alias = $this->alias('other-me@example.test');
        $draft = $this->addressedDraft();

        $result = $this->handle([
            'create' => ['s1' => [
                'emailId' => (string) $draft->id,
                'identityId' => (string) $alias->id,
                'envelope' => ['mailFrom' => ['email' => 'Other-Me@example.test']],
            ]],
        ]);

        self::assertArrayHasKey('s1', (array) $result['created']);
    }

    public function testAnEnvelopeNamingDifferentRecipientsIsRefused(): void
    {
        $draft = $this->addressedDraft();

        $result = $this->handle([
            'create' => ['s1' => [
                'emailId' => (string) $draft->id,
                'envelope' => ['rcptTo' => [['email' => 'somebody@example.test']]],
            ]],
        ]);

        self::assertSame('invalidRecipients', ((array) $result['notCreated'])['s1']['type']);
    }

    /** A client that echoes the Email's own recipients is describing this mail. */
    public function testAnEnvelopeRepeatingTheEmailsRecipientsIsAccepted(): void
    {
        $draft = $this->addressedDraft();

        $result = $this->handle([
            'create' => ['s1' => [
                'emailId' => (string) $draft->id,
                'envelope' => ['rcptTo' => [['email' => 'recipient@example.test', 'parameters' => null]]],
            ]],
        ]);

        self::assertArrayHasKey('s1', (array) $result['created']);
    }

    // ── cancelling a held submission ──────────────────────────────────────

    /**
     * The same flag the web composer's undo button sets, read by the same
     * handler when the envelope comes due. Nothing is pulled back out of the
     * queue — the send declines to happen.
     */
    public function testAPendingSubmissionCanBeCanceled(): void
    {
        $draft = $this->addressedDraft();

        $this->handle([
            'create' => ['s1' => [
                'emailId' => (string) $draft->id,
                'envelope' => ['mailFrom' => ['parameters' => ['HOLDFOR' => '3600']]],
            ]],
        ]);

        $result = $this->handle(['update' => [(string) $draft->id => ['undoStatus' => 'canceled']]]);

        self::assertSame([(string) $draft->id => null], (array) $result['updated']);
        self::assertTrue($draft->cancelled);
    }

    /** A mail that has left cannot be recalled, and saying otherwise would be a lie. */
    public function testASentSubmissionCannotBeUnsent(): void
    {
        $draft = $this->addressedDraft();
        $draft->sentAt = new \DateTimeImmutable('-1 minute');
        $this->em->flush();

        $result = $this->handle(['update' => [(string) $draft->id => ['undoStatus' => 'canceled']]]);

        self::assertSame('cannotUnsend', ((array) $result['notUpdated'])[(string) $draft->id]['type']);
        self::assertFalse($draft->cancelled);
    }

    /** undoStatus is the only writable property, and "canceled" its only value. */
    public function testAnUpdateOfAnythingElseIsRefused(): void
    {
        $draft = $this->addressedDraft();

        $result = $this->handle(['update' => [(string) $draft->id => ['identityId' => '1']]]);

        self::assertSame('invalidProperties', ((array) $result['notUpdated'])[(string) $draft->id]['type']);
        self::assertFalse($draft->cancelled);
    }

    public function testUndoStatusCanOnlyBeSetToCanceled(): void
    {
        $draft = $this->addressedDraft();

        $result = $this->handle(['update' => [(string) $draft->id => ['undoStatus' => 'final']]]);

        self::assertSame('invalidProperties', ((array) $result['notUpdated'])[(string) $draft->id]['type']);
    }

    public function testCancellingSomethingThatIsNotAnEmailOfThisAccountIsNotFound(): void
    {
        $result = $this->handle(['update' => ['999999' => ['undoStatus' => 'canceled']]]);

        self::assertSame('notFound', ((array) $result['notUpdated'])['999999']['type']);
    }

    /**
     * The flag is consumed by whichever envelope comes due first, so a cancel
     * left over from an abandoned hold would swallow the next genuine send and
     * report "pending" for mail that never goes out.
     */
    public function testSubmittingAgainClearsACancelThatWasNeverSpent(): void
    {
        $draft = $this->addressedDraft();
        $draft->cancelled = true;
        $this->em->flush();

        $this->handle(['create' => ['s1' => ['emailId' => (string) $draft->id]]]);

        self::assertFalse($draft->cancelled);
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

    /**
     * The hold each queued send carries, in milliseconds — null where there is
     * no DelayStamp at all, which is what an immediate send must look like.
     *
     * The transport is in-memory here, so nothing is ever delivered: the
     * envelope and its stamps are the whole of what this method promises.
     *
     * @return list<int|null>
     */
    private function queuedDelays(): array
    {
        $delays = [];

        foreach ($this->transport->getSent() as $envelope) {
            if (false === $envelope->getMessage() instanceof SendMessageMessage) {
                continue;
            }

            $delays[] = $envelope->last(DelayStamp::class)?->getDelay();
        }

        return $delays;
    }

    /** A sendable alias, which is what an Identity is. */
    private function alias(string $address, ?Account $account = null): EmailAlias
    {
        $account ??= $this->account;

        $alias = new EmailAlias($account, $address, EmailAliasSource::Manual, EmailAliasStatus::Active);
        $account->addAlias($alias);
        $this->em->persist($alias);
        $this->em->flush();

        return $alias;
    }

    private function addressedDraft(): Message
    {
        $draft = $this->draftMessage();
        $draft->toAddresses = [['name' => null, 'address' => 'recipient@example.test']];
        $this->em->flush();

        return $draft;
    }
}
