<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\SendFailure;
use App\Domain\Exception\JmapApiException;
use App\Domain\Exception\JmapThrottledException;
use App\Domain\Exception\SendDeferredException;
use App\Domain\Exception\SendFailureException;
use App\Domain\Exception\SendOutcomeUnknownException;
use App\Domain\Exception\SendRefusedException;
use App\Domain\Helper\ApiMime;
use App\Domain\Helper\SendTransportFailure;
use App\Domain\Interface\MailDeliveryInterface;
use App\Domain\Interface\SentCopyLookupInterface;
use App\Entity\Mail\Account;
use App\Infrastructure\JmapClient\JmapCapability;
use App\Infrastructure\JmapClient\JmapClient;
use App\Service\JmapClient\JmapMailboxSyncer;
use App\Service\Label\LabelResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Send path for JMAP accounts whose server offers submission (RFC 8621 §7).
 *
 * The MIME is the one MessageSendService builds for every provider — uploaded
 * as a blob, imported as an Email into Drafts, and submitted in the same
 * request, with onSuccessUpdateEmail moving it from Drafts to Sent once the
 * server has accepted it. So the server files the Sent copy itself, as Gmail
 * and Graph do, and the next sync finds it there and recognises it by
 * Message-ID as the row this send already wrote.
 *
 * A JMAP server without submission is not claimed: supports() says no, and
 * SmtpMailSender beneath it in the registry sends instead, leaving the Sent
 * copy to the server — see MessageSendService::appendToSentFolder().
 *
 * ## What a failure means for the next attempt
 *
 * The split GmailApiSender and GraphApiSender make (#125). The upload and the
 * import happen before anything is sent, so every failure there is a deferral
 * or a refusal. Only the request carrying EmailSubmission/set can have sent
 * the mail without saying so: a gateway that lost the answer, or a connection
 * that dropped once the request had left. Those are SendOutcomeUnknown, and
 * isInSent() is what settles them.
 *
 * An Email imported for a submission that did not go out is destroyed again,
 * so a retry does not leave a trail of identical drafts on the server.
 */
class JmapApiSender implements MailDeliveryInterface, SentCopyLookupInterface
{
    /** SetError types that mean this message will not be sent as it is. */
    private const array REFUSALS = [
        'forbiddenMailFrom', 'forbiddenFrom', 'forbiddenToSend', 'invalidEmail',
        'tooManyRecipients', 'noRecipients', 'invalidRecipients', 'tooLarge',
        'invalidProperties', 'forbidden',
    ];

    /**
     * How many of Sent's newest messages isInSent() reads when the header
     * filter finds nothing. The question is asked minutes after the attempt,
     * so the message, if it was sent, is among the newest; a mailbox that
     * gained this many since is not one an unknown outcome is settled in.
     */
    private const int SENT_SCAN = 50;

    public function __construct(
        private readonly JmapClient        $jmap,
        private readonly JmapMailboxSyncer $mailboxes,
        private readonly LabelResolver     $labels,
        private readonly LoggerInterface   $logger,
    ) {
    }

    /**
     * A JMAP account whose server will submit mail.
     *
     * A session that cannot be fetched just now answers yes: the account is
     * JMAP and its sends belong here, and deliver() reports the outage as the
     * deferral it is. Answering no would hand the mail to SMTP, which this
     * account may have no settings for at all.
     */
    public function supports(Account $account): bool
    {
        if (false === $account->isJmap()) {
            return false;
        }

        try {
            return null !== $this->jmap->session($account)->accountFor(JmapCapability::SUBMISSION);
        } catch (JmapApiException) {
            return true;
        }
    }

    public function filesSentCopy(): bool
    {
        return true;
    }

    public function send(Email $email, Account $account): bool
    {
        try {
            $this->deliver($email, $account);
        } catch (\Throwable $e) {
            // The callers of send() want a yes or a no — see GraphApiSender.
            if (false === $e instanceof SendFailureException) {
                $this->logger->error('JmapApiSender: send failed', [
                    'account'   => $account->id,
                    'error'     => $e->getMessage(),
                    'exception' => $e,
                ]);
            }

            return false;
        }

        return true;
    }

    public function deliver(Email $email, Account $account): void
    {
        // Not $email->toString(): that drops Bcc, and the server reads the
        // recipients from the MIME. See ApiMime.
        $mime = ApiMime::toString($email);

        try {
            $this->mailboxes->sync($account);

            $accountId  = $this->mailboxes->mailAccountId($account);
            $submitter  = $this->jmap->session($account)->accountFor(JmapCapability::SUBMISSION) ?? $accountId;
            $drafts     = $this->mailboxId($account, LabelRole::Drafts);
            $sent       = $this->mailboxId($account, LabelRole::Sent);
            $identityId = $this->identityFor($account, $submitter, $email);
            $blobId     = $this->jmap->upload($account, $accountId, $mime, 'message/rfc822');
        } catch (JmapApiException $e) {
            throw $this->beforeSending($account, $e);
        }

        $home = $drafts ?? $sent;

        if (null === $home) {
            throw new SendRefusedException('The JMAP server has neither a Drafts nor a Sent mailbox to send from.', SendFailure::Refused);
        }

        $onSuccess = ['keywords/$draft' => null, 'keywords/$seen' => true];

        if (null !== $sent && $sent !== $home) {
            $onSuccess['mailboxIds/' . $home] = null;
            $onSuccess['mailboxIds/' . $sent] = true;
        }

        try {
            $response = $this->jmap->call($account, [JmapCapability::MAIL, JmapCapability::SUBMISSION], [
                ['Email/import', ['accountId' => $accountId, 'emails' => ['m' => [
                    'blobId'     => $blobId,
                    'mailboxIds' => [$home => true],
                    'keywords'   => ['$draft' => true, '$seen' => true],
                ]]], 'import'],
                ['EmailSubmission/set', [
                    'accountId'            => $submitter,
                    'create'               => ['s' => ['identityId' => $identityId, 'emailId' => '#m']],
                    'onSuccessUpdateEmail' => ['#s' => $onSuccess],
                ], 'send'],
            ]);
        } catch (JmapThrottledException $e) {
            $this->logger->warning('JmapApiSender: the server is limiting sends; trying again', $this->context($account, $e));

            throw new SendDeferredException($e->getMessage(), SendFailure::RateLimited, $e->getRetryAfterSeconds(), $e);
        } catch (JmapApiException $e) {
            throw $this->whileSending($account, $e);
        }

        try {
            $imported = $response->get('import', 'Email/import');
        } catch (JmapApiException $e) {
            // The import was refused outright, so the submission had nothing
            // to send: the server answered for both, and nothing left.
            throw $this->refusedOrDeferred($account, $e);
        }

        $emailId = $imported['created']['m']['id'] ?? null;

        if (false === is_string($emailId)) {
            $type = $imported['notCreated']['m']['type'] ?? 'unknown';

            throw new SendRefusedException(sprintf('The JMAP server would not take the message (%s).', $type), SendFailure::Refused);
        }

        try {
            $submitted = $response->get('send', 'EmailSubmission/set');
        } catch (JmapApiException $e) {
            $this->discard($account, $accountId, $emailId);

            throw $this->refusedOrDeferred($account, $e);
        }

        if (true === is_array($submitted['created']['s'] ?? null)) {
            return;
        }

        $type = (string) ($submitted['notCreated']['s']['type'] ?? 'unknown');

        $this->discard($account, $accountId, $emailId);

        $this->logger->error('JmapApiSender: send refused', ['account' => $account->id, 'type' => $type]);

        if (false === in_array($type, self::REFUSALS, true)) {
            throw new SendDeferredException(sprintf('The JMAP server did not send the message (%s).', $type), SendFailure::Unavailable);
        }

        throw new SendRefusedException(sprintf('The JMAP server refused to send the message (%s).', $type), SendFailure::Refused);
    }

    /**
     * Whether the account's Sent mailbox holds a message with this Message-ID.
     *
     * Email/query with a header filter first, which RFC 8621 matches as a
     * substring of the header value — the bracket-less id is inside the
     * bracketed one, so either spelling on the server is found. Fastmail
     * answers it.
     *
     * Stalwart does not: up to 0.16 a header condition matches nothing, for
     * any header and any value, and "not in Sent" is the answer that sends a
     * mail a second time. So an empty answer is not taken as no — Sent's
     * newest messages are read instead and their Message-IDs compared here.
     */
    public function isInSent(Account $account, string $messageId): bool
    {
        $accountId = $this->mailboxes->mailAccountId($account);
        $sent      = $this->mailboxId($account, LabelRole::Sent);
        $filter    = ['header' => ['Message-ID', $messageId]];

        if (null !== $sent) {
            $filter = ['operator' => 'AND', 'conditions' => [$filter, ['inMailbox' => $sent]]];
        }

        $result = $this->jmap->call($account, [JmapCapability::MAIL], [
            ['Email/query', ['accountId' => $accountId, 'filter' => $filter, 'limit' => 1], 'q'],
        ])->get('q', 'Email/query');

        if ([] !== ($result['ids'] ?? []) || null === $sent) {
            return [] !== ($result['ids'] ?? []);
        }

        return $this->isAmongNewestSent($account, $accountId, $sent, $messageId);
    }

    /**
     * The comparison isInSent() falls back to: Sent's newest messages, by
     * their parsed messageId, which a server gives without brackets.
     */
    private function isAmongNewestSent(Account $account, string $accountId, string $sent, string $messageId): bool
    {
        $newest = $this->jmap->call($account, [JmapCapability::MAIL], [
            ['Email/query', [
                'accountId' => $accountId,
                'filter'    => ['inMailbox' => $sent],
                'sort'      => [['property' => 'receivedAt', 'isAscending' => false]],
                'limit'     => self::SENT_SCAN,
            ], 'q'],
        ])->get('q', 'Email/query');

        $ids = array_values(array_filter($newest['ids'] ?? [], is_string(...)));

        if ([] === $ids) {
            return false;
        }

        $emails = $this->jmap->call($account, [JmapCapability::MAIL], [
            ['Email/get', ['accountId' => $accountId, 'ids' => $ids, 'properties' => ['messageId']], 'g'],
        ])->get('g', 'Email/get');

        $wanted = strtolower(trim($messageId, '<> '));

        foreach ($emails['list'] ?? [] as $email) {
            foreach (is_array($email['messageId'] ?? null) ? $email['messageId'] : [] as $id) {
                if (true === is_string($id) && $wanted === strtolower(trim($id, '<> '))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The identity to send as: the one whose address is the From address, or
     * the first the server offers.
     *
     * The first is a guess that a server may refuse with forbiddenFrom, which
     * is then reported as the refusal it is. Choosing nothing would be
     * refused every time.
     *
     * @throws JmapApiException
     */
    private function identityFor(Account $account, string $submitter, Email $email): string
    {
        $identities = $this->jmap->call($account, [JmapCapability::SUBMISSION], [
            ['Identity/get', ['accountId' => $submitter, 'ids' => null], 'i'],
        ])->get('i', 'Identity/get');

        $list = array_values(array_filter(
            is_array($identities['list'] ?? null) ? $identities['list'] : [],
            static fn (mixed $identity): bool => is_array($identity) && is_string($identity['id'] ?? null),
        ));

        if ([] === $list) {
            throw new JmapApiException('The JMAP server offers no identity to send as.', 0, 'forbiddenFrom');
        }

        $from = array_map(static fn (Address $address): string => strtolower($address->getAddress()), $email->getFrom());

        foreach ($list as $identity) {
            if (true === in_array(strtolower((string) ($identity['email'] ?? '')), $from, true)) {
                return $identity['id'];
            }
        }

        return $list[0]['id'];
    }

    private function mailboxId(Account $account, LabelRole $role): ?string
    {
        return $this->labels->systemLabel($role, $account)->bindingFor($account)?->jmapMailboxId;
    }

    /**
     * Destroy an Email imported for a submission that did not go out.
     *
     * Best effort: the send has already failed, and a draft left behind is
     * clutter on the server, not a second copy on the wire.
     */
    private function discard(Account $account, string $accountId, string $emailId): void
    {
        try {
            $this->jmap->call($account, [JmapCapability::MAIL], [
                ['Email/set', ['accountId' => $accountId, 'destroy' => [$emailId]], 'd'],
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('JmapApiSender: the unsent copy could not be removed', $this->context($account, $e));
        }
    }

    /**
     * A failure before the submission request: nothing can have been sent.
     */
    private function beforeSending(Account $account, JmapApiException $e): SendFailureException
    {
        if ($e instanceof JmapThrottledException) {
            return new SendDeferredException($e->getMessage(), SendFailure::RateLimited, $e->getRetryAfterSeconds(), $e);
        }

        return $this->refusedOrDeferred($account, $e);
    }

    /**
     * A failure of the request that carried the submission.
     *
     * Only here can the mail have left without the server saying so.
     */
    private function whileSending(Account $account, JmapApiException $e): SendFailureException
    {
        $status = $e->getStatus();
        $cause  = $e->getPrevious();

        if (502 === $status || 504 === $status) {
            $this->logger->error('JmapApiSender: a gateway lost the answer to a send', $this->context($account, $e));

            return new SendOutcomeUnknownException($e->getMessage(), $e);
        }

        if (0 === $status && null === $e->getType()) {
            if (null !== $cause && true === SendTransportFailure::beforeRequest($cause)) {
                $this->logger->warning('JmapApiSender: the server could not be reached; trying again', $this->context($account, $e));

                return new SendDeferredException($e->getMessage(), SendFailure::Unavailable, null, $e);
            }

            $this->logger->error('JmapApiSender: the answer to a send was lost', $this->context($account, $e));

            return new SendOutcomeUnknownException($e->getMessage(), $e);
        }

        return $this->refusedOrDeferred($account, $e);
    }

    private function refusedOrDeferred(Account $account, JmapApiException $e): SendFailureException
    {
        $status = $e->getStatus();

        if ($status >= 500 || (0 === $status && null === $e->getType()) || true === in_array($e->getType(), ['serverFail', 'serverUnavailable'], true)) {
            $this->logger->warning('JmapApiSender: the server failed on its side; trying again', $this->context($account, $e));

            return new SendDeferredException($e->getMessage(), SendFailure::Unavailable, null, $e);
        }

        $this->logger->error('JmapApiSender: send refused', $this->context($account, $e));

        return new SendRefusedException($e->getMessage(), SendFailure::Refused, $e);
    }

    /** @return array<string, mixed> */
    private function context(Account $account, \Throwable $e): array
    {
        return ['account' => $account->id, 'error' => $e->getMessage(), 'exception' => $e];
    }
}
