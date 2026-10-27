<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Exception\JmapApiException;
use App\Domain\Exception\JmapThrottledException;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Infrastructure\JmapClient\JmapCapability;
use App\Infrastructure\JmapClient\JmapClient;
use App\Infrastructure\Messaging\Message\ApplyJmapChangesMessage;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\JmapClient\JmapMailboxSyncer;
use App\Service\JmapClient\JmapMailboxWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Applies a change made here to the Emails on a JMAP account's server.
 *
 * One Email/set with a patch per Email: `mailboxIds/<id>` set or nulled for
 * the labels, `keywords/<keyword>` for read and starred. A patch rather than
 * the whole property, so a mailbox the server knows and plMail does not — a
 * shared folder, a mailbox another client made a moment ago — is never taken
 * off a message by a change that was not about it.
 *
 * ## Failure
 *
 * Throttling and transport failures leave the handler, so the export queue
 * retries them; the local change already stands either way. A refusal is
 * recorded on the account as Gmail's permanent refusal is — see
 * Account::$exportRefusedReason — because it will not fix itself and a change
 * that silently never reaches the server is undone by the next sync. An Email
 * the server no longer has is not a failure: the end state the change wanted
 * is moot.
 */
#[AsMessageHandler]
final readonly class ApplyJmapChangesHandler
{
    public function __construct(
        private AccountRepository      $accounts,
        private MessageRepository      $messages,
        private LabelRepository        $labels,
        private JmapClient             $jmap,
        private JmapMailboxSyncer      $mailboxes,
        private JmapMailboxWriter      $writer,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {
    }

    public function __invoke(ApplyJmapChangesMessage $message): void
    {
        $account = $this->accounts->find($message->accountId);

        if (null === $account || false === $account->isJmap()) {
            return;
        }

        /** @var list<Message> $rows */
        $rows = array_values(array_filter(
            $this->messages->findBy(['id' => $message->messageIds, 'account' => $account]),
            static fn (Message $row): bool => null !== $row->jmapId,
        ));

        if ([] === $rows) {
            return;
        }

        try {
            $accountId = $this->mailboxes->mailAccountId($account);
            $arguments = ['accountId' => $accountId];

            if (true === $message->destroy) {
                $arguments['destroy'] = array_map(static fn (Message $row): string => (string) $row->jmapId, $rows);
            } else {
                $patch = $this->patch($account, $message);

                if ([] === $patch) {
                    return;
                }

                $arguments['update'] = [];

                foreach ($rows as $row) {
                    $arguments['update'][(string) $row->jmapId] = $patch;
                }
            }

            $result = $this->jmap->call($account, [JmapCapability::MAIL], [
                ['Email/set', $arguments, 's'],
            ])->get('s', 'Email/set');
        } catch (JmapThrottledException $throttled) {
            throw $throttled;
        } catch (JmapApiException $refused) {
            if (true === self::isTransient($refused)) {
                throw $refused;
            }

            $this->refused($account, $refused->getMessage());

            return;
        }

        $this->settle($account, $rows, $result);
    }

    /**
     * The patch every Email in the job gets.
     *
     * @return array<string, mixed>
     *
     * @throws JmapApiException
     */
    private function patch(Account $account, ApplyJmapChangesMessage $message): array
    {
        $patch = [];

        if (null !== $message->only) {
            $role = LabelRole::tryFrom($message->only);
            $id   = null === $role ? null : $this->writer->ensureRole($role, $account);

            if (null !== $id) {
                // The whole property: on its own in the bin, out of the rest.
                $patch['mailboxIds'] = [$id => true];
            }
        } else {
            foreach ($message->remove as $entry) {
                $id = $this->mailboxId($account, $entry, false);

                if (null !== $id) {
                    $patch['mailboxIds/' . $id] = null;
                }
            }

            // After the removals, so an entry named on both sides — a label
            // taken off and put back by two quick clicks — ends up on.
            foreach ($message->add as $entry) {
                $id = $this->mailboxId($account, $entry, true);

                if (null !== $id) {
                    $patch['mailboxIds/' . $id] = true;
                }
            }
        }

        foreach ($message->keywords as $keyword => $set) {
            $patch['keywords/' . $keyword] = true === $set ? true : null;
        }

        return $patch;
    }

    /**
     * The remote mailbox an entry names, made on the server for an add.
     *
     * Only an add creates anything. Removing a label the server never had is
     * removing nothing, and creating a mailbox in order to take a message out
     * of it would leave an empty mailbox behind for nothing.
     *
     * @throws JmapApiException
     */
    private function mailboxId(Account $account, string $entry, bool $create): ?string
    {
        if (true === str_starts_with($entry, ApplyJmapChangesMessage::ROLE_PREFIX)) {
            $role = LabelRole::tryFrom(substr($entry, strlen(ApplyJmapChangesMessage::ROLE_PREFIX)));

            if (null === $role) {
                return null;
            }

            return true === $create
                ? $this->writer->ensureRole($role, $account)
                : $this->labels->findOneByRoleForUser($role, $account->usr)?->bindingFor($account)?->jmapMailboxId;
        }

        $label = $this->labels->find((int) $entry);

        if (null === $label || $label->usr !== $account->usr) {
            return null;
        }

        return true === $create ? $this->writer->ensure($label, $account) : $label->bindingFor($account)?->jmapMailboxId;
    }

    /**
     * Read the server's answer: confirm what it took, record what it refused.
     *
     * A confirmed row has its in-flight mark cleared, as ApplyImapFlagsHandler
     * clears it — the server has the change now, so the next sync's answer is
     * no longer stale and may be applied.
     *
     * @param list<Message>        $rows
     * @param array<string, mixed> $result
     */
    private function settle(Account $account, array $rows, array $result): void
    {
        $refusals = array_merge(
            is_array($result['notUpdated'] ?? null) ? $result['notUpdated'] : [],
            is_array($result['notDestroyed'] ?? null) ? $result['notDestroyed'] : [],
        );

        $refused = [];

        foreach ($rows as $row) {
            $error = $refusals[(string) $row->jmapId] ?? null;

            if (null === $error) {
                $row->flagsTouchedAt = null;

                continue;
            }

            $type = is_array($error) && is_string($error['type'] ?? null) ? $error['type'] : 'unknown';

            if ('notFound' !== $type) {
                $refused[] = $type;
            }
        }

        if ([] !== $refused) {
            $this->refused($account, sprintf('JMAP server refused Email/set (%s).', implode(', ', array_unique($refused))));
        } elseif (null !== $account->exportRefusedReason) {
            // It worked, so whatever was wrong is not wrong now — the rule
            // ApplyGmailLabelsHandler follows, for the health card's sake.
            $account->exportRefusedReason = null;
        }

        $this->em->flush();
    }

    /**
     * Whether asking again could get a different answer.
     *
     * The server never answered (no status, no type), answered with a server
     * error, or said so in JMAP's own words. Everything else — a 4xx, a
     * method refused as forbidden or read-only — is the server's considered
     * answer, and the retry ladder would only hear it four more times.
     */
    private static function isTransient(JmapApiException $refused): bool
    {
        $status = $refused->getStatus();
        $type   = $refused->getType();

        return (0 === $status && null === $type)
            || $status >= 500
            || true === in_array($type, ['serverFail', 'serverUnavailable', 'serverPartialFail'], true);
    }

    private function refused(Account $account, string $reason): void
    {
        $this->logger->error('ApplyJmapChanges: the server refused the change', [
            'accountId' => $account->id,
            'reason'    => $reason,
        ]);

        $account->exportRefusedReason = mb_substr($reason, 0, 500);
        $this->em->flush();
    }
}
