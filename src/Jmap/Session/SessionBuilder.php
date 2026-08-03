<?php

declare(strict_types=1);

namespace App\Jmap\Session;

use App\Entity\User\User;
use App\Jmap\Protocol\Capability;
use App\Jmap\State\StateManager;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the JMAP Session object (RFC 8620 §2) returned from /jmap/session and
 * /.well-known/jmap. One JMAP account is exposed per connected mail account, so
 * a single login enumerates all of the user's mail; a unified inbox is a
 * client-side concern (one Email/query per account, merged in the client).
 *
 * NOTE — the ONLY place coupled to the mail-account entity shape. It uses:
 *   User::$accounts: iterable<Account>
 *   Account::getId(): ?int
 *   Account::getEmail(): ?string
 * Adjust these three calls if the accessors move and nothing else changes.
 */
final class SessionBuilder
{
    public function __construct(
        private readonly StateManager $stateManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $vapidPublicKey,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function build(User $user): array
    {
        $apiUrl = $this->urlGenerator->generate('jmap_api', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $base = (string) preg_replace('#/jmap/api$#', '', $apiUrl);

        $accounts = [];
        $primaryId = null;

        foreach ($user->accounts as $account) {
            $accountId = (string) $account->getId();
            $primaryId ??= $accountId;

            $accounts[$accountId] = [
                'name' => (string) $account->getEmail(),
                'isPersonal' => true,
                'isReadOnly' => false,
                'accountCapabilities' => [
                    Capability::MAIL => $this->mailAccountCapabilities(),
                    Capability::SUBMISSION => $this->submissionCapabilities(),
                ],
            ];
        }

        $primaryAccounts = [];

        if (null !== $primaryId) {
            $primaryAccounts[Capability::MAIL] = $primaryId;
        }

        // Empty JMAP maps must serialise as {} rather than [].
        $accountsValue = new \stdClass();

        if (count($accounts) > 0) {
            $accountsValue = $accounts;
        }

        $primaryAccountsValue = new \stdClass();

        if (count($primaryAccounts) > 0) {
            $primaryAccountsValue = $primaryAccounts;
        }

        return [
            'capabilities' => [
                Capability::CORE => $this->coreCapabilities(),
                Capability::MAIL => new \stdClass(),
                Capability::SUBMISSION => new \stdClass(),
                Capability::PUSH => $this->pushCapabilities(),
            ],
            'accounts' => $accountsValue,
            'primaryAccounts' => $primaryAccountsValue,
            'username' => $user->getUserIdentifier(),
            'apiUrl' => $apiUrl,
            'downloadUrl' => $base.'/jmap/download/{accountId}/{blobId}/{name}?accept={type}',
            'uploadUrl' => $base.'/jmap/upload/{accountId}',
            'eventSourceUrl' => $base.'/jmap/eventsource?types={types}&closeafter={closeafter}&ping={ping}',
            'state' => $this->stateManager->sessionState($user),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function coreCapabilities(): array
    {
        return [
            'maxSizeUpload' => 50_000_000,
            'maxConcurrentUpload' => 4,
            'maxSizeRequestObject' => 10_000_000,
            'maxConcurrentRequests' => 4,
            'maxCallsInRequest' => 32,
            'maxObjectsInGet' => 500,
            'maxObjectsInSet' => 500,
            'collationAlgorithms' => [
                'i;ascii-numeric',
                'i;ascii-casemap',
                'i;unicode-casemap',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function mailAccountCapabilities(): array
    {
        return [
            'maxMailboxesPerEmail' => null,
            'maxMailboxDepth' => null,
            'maxSizeMailboxName' => 255,
            'maxSizeAttachmentsPerEmail' => 50_000_000,
            'emailQuerySortOptions' => ['receivedAt', 'from', 'to', 'subject', 'size'],
            'mayCreateTopLevelMailbox' => true,
        ];
    }

    /**
     * The VAPID public key clients pass as applicationServerKey when creating
     * a browser push subscription. Empty when Web Push is unconfigured, which
     * is a client's signal not to offer push at all.
     *
     * @return array<string,mixed>
     */
    private function pushCapabilities(): array
    {
        return ['vapidPublicKey' => $this->vapidPublicKey];
    }

    /**
     * Sending is queued on the messenger bus with no scheduling support, so
     * there is no future-send window to advertise.
     *
     * @return array<string,mixed>
     */
    private function submissionCapabilities(): array
    {
        return [
            'maxDelayedSend' => 0,
            'submissionExtensions' => new \stdClass(),
        ];
    }
}
