<?php

declare(strict_types=1);

namespace App\Jmap\Method\Mail;

use App\Entity\Mail\MessageThread;
use App\Jmap\Account\AccountResolver;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Mail\MessageThreadRepository;
use App\Service\Mail\ThreadSnoozeService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Thread/set" — a plMail extension, not RFC 8621.
 *
 * The spec's Thread is read-only: it has no /set, because a thread is derived
 * from its Emails and there is nothing on it to change. plMail's is different
 * in exactly one way — a thread can be snoozed, and that state belongs to the
 * thread rather than to any message in it.
 *
 * Snoozing is not a flag. Setting `snoozedUntil` moves the conversation out of
 * the Inbox and into Snoozed, and app:mail:wake-snoozed brings it back later —
 * see ThreadSnoozeService, which the web UI goes through as well, so a snooze
 * means the same thing whichever client set it.
 *
 * Deliberately narrow. `create` and `destroy` are refused outright: threads
 * come into being by mail arriving and go away when their last message does,
 * and a client that could conjure one would be describing something the rest
 * of the system has no meaning for. `update` accepts one property.
 *
 * Standard clients neither know nor need this method — Thread/get still
 * answers the spec's two properties plus one they will ignore.
 */
final class ThreadSetMethod implements JmapMethod
{
    private const int MAX_OBJECTS = 500;

    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly MessageThreadRepository $threadRepository,
        private readonly StateManager $stateManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly ThreadSnoozeService $snoozeService,
    ) {
    }

    public function name(): string
    {
        return 'Thread/set';
    }

    public function handle(array $arguments, JmapContext $context): array
    {
        $account = $this->accountResolver->resolve($context->user, $arguments['accountId'] ?? null);
        $accountId = (int) $account->id;

        $oldState = $this->stateManager->stateFor($accountId, JmapObjectType::Thread);

        foreach (['create', 'destroy'] as $unsupported) {
            $value = $arguments[$unsupported] ?? null;

            if (null !== $value && [] !== $value) {
                throw new MethodException(
                    'invalidArguments',
                    sprintf('Thread/set does not support "%s".', $unsupported),
                );
            }
        }

        $update = $arguments['update'] ?? [];

        if (false === is_array($update)) {
            throw new MethodException('invalidArguments', '"update" must be an object.');
        }

        if (count($update) > self::MAX_OBJECTS) {
            throw new MethodException(
                'requestTooLarge',
                sprintf('At most %d objects per Thread/set.', self::MAX_OBJECTS),
            );
        }

        $updated = [];
        $notUpdated = [];

        foreach ($update as $rawId => $patch) {
            $id = $context->resolveId((string) $rawId) ?? (string) $rawId;

            // Scoped by account, so another user's thread id resolves to
            // nothing rather than being reachable by guessing an integer.
            $threads = $this->threadRepository->findByAccountAndIds($accountId, [(int) $id]);
            $thread = $threads[0] ?? null;

            if (null === $thread) {
                $notUpdated[(string) $rawId] = [
                    'type' => 'notFound',
                    'description' => 'No such thread in this account.',
                ];

                continue;
            }

            try {
                $this->applyPatch($thread, $patch);
            } catch (MethodException $error) {
                $notUpdated[(string) $rawId] = $error->toError();

                continue;
            }

            // Null: nothing was changed beyond what was asked for, which is
            // what RFC 8620 §5.3 wants said here. The label moves are not a
            // server-side surprise — they are what snoozing means.
            $updated[(string) $id] = null;
        }

        // No flush here: the snooze service records its own state changes and
        // flushes, because the label moves it makes have to be visible to the
        // propagator jobs it queues.

        return [
            'accountId' => (string) $accountId,
            'oldState' => $oldState,
            'newState' => $this->stateManager->stateFor($accountId, JmapObjectType::Thread),
            'created' => null,
            'updated' => (object) $updated,
            'destroyed' => [],
            'notCreated' => (object) [],
            'notUpdated' => (object) $notUpdated,
            'notDestroyed' => (object) [],
        ];
    }

    /**
     * @param mixed $patch the JMAP patch object for one thread
     *
     * @throws MethodException when the patch names anything but snoozedUntil
     */
    private function applyPatch(MessageThread $thread, mixed $patch): void
    {
        if (false === is_array($patch)) {
            throw new MethodException('invalidPatch', 'Each update must be an object.');
        }

        foreach (array_keys($patch) as $property) {
            if ('snoozedUntil' !== $property) {
                throw new MethodException(
                    'invalidProperties',
                    sprintf('"%s" is not a settable Thread property.', (string) $property),
                );
            }
        }

        if (false === array_key_exists('snoozedUntil', $patch)) {
            // An empty patch is a no-op rather than an error: it changes
            // nothing, and refusing it would make a client's "apply whatever
            // changed" loop fail on the case where nothing did.
            return;
        }

        $until = $this->snoozeDate($patch['snoozedUntil']);

        // Not a field assignment: snoozing moves the conversation out of the
        // Inbox and clearing brings it back, both propagated outward. Writing
        // the column directly would leave a thread marked snoozed and still
        // sitting in the inbox.
        if (null === $until) {
            $this->snoozeService->wake($thread);
        } else {
            $this->snoozeService->snooze($thread, $until);
        }
    }

    /**
     * Null clears the snooze — the same convention the web UI's endpoint uses.
     *
     * A malformed date is refused rather than coerced. The web endpoint quietly
     * substitutes "in 1 day" on a parse failure, which is defensible for a form
     * post and wrong for an API: a client would be told its write succeeded and
     * find the thread reappearing at a time it never asked for.
     */
    private function snoozeDate(mixed $value): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }

        if (false === is_string($value) || '' === trim($value)) {
            throw new MethodException(
                'invalidProperties',
                '"snoozedUntil" must be a UTC date-time string or null.',
            );
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new MethodException(
                'invalidProperties',
                sprintf('"%s" is not a parseable date-time.', $value),
            );
        }
    }
}
