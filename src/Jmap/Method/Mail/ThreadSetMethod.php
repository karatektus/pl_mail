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
 * The second settable property is `isNew`, and only ever `false`. That is the
 * JMAP half of the New marker: the web retires a badge by rendering the row
 * (ThreadListRenderer) or by POSTing ids back after a Turbo prefetch, and
 * before this a JMAP client could neither read the marker nor clear it — so a
 * user who triaged on their phone opened the browser to find every conversation
 * from the last day still badged. Retiring is one-way by construction: `true`
 * is refused, because "make this new again" is not a thing the product means
 * and a settable timestamp would let a client invent a display that never
 * happened.
 *
 * Deliberately narrow. `create` and `destroy` are refused outright: threads
 * come into being by mail arriving and go away when their last message does,
 * and a client that could conjure one would be describing something the rest
 * of the system has no meaning for.
 *
 * Standard clients neither know nor need this method — Thread/get still
 * answers the spec's two properties plus three they will ignore.
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

        // Threads whose patch asked for the New marker to be retired,
        // collected rather than written one at a time. A list client sends
        // this for every row it draws, so a page is up to fifty of them in one
        // call -- markListed() is a single UPDATE with its own
        // `listedAt IS NULL` guard, where fifty entity writes would be fifty
        // dirty objects for a column nothing else in this request reads.
        //
        // The entities and not just their ids, because the UPDATE has to be
        // reconciled with the identity map afterwards -- see below.
        $shown = [];

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
                if (true === $this->applyPatch($thread, $patch)) {
                    $shown[] = $thread;
                }
            } catch (MethodException $error) {
                $notUpdated[(string) $rawId] = $error->toError();

                continue;
            }

            // Null: nothing was changed beyond what was asked for, which is
            // what RFC 8620 §5.3 wants said here. The label moves are not a
            // server-side surprise — they are what snoozing means.
            $updated[(string) $id] = null;
        }

        // The only write this method makes itself. The snooze path flushes
        // inside ThreadSnoozeService, because the label moves it makes have to
        // be visible to the propagator jobs it queues; retiring a marker moves
        // nothing and queues nothing, so it is one statement here.
        //
        // Deliberately NOT recorded as a Thread state change. The web retires
        // markers the same way -- a direct UPDATE, no change log -- and a
        // phone drawing a page of mail would otherwise push fifty state
        // changes to every other device the user owns, for a column none of
        // them needs told about urgently. The next ordinary Thread/get carries
        // the new value.
        if ([] !== $shown) {
            $this->threadRepository->markListed(
                array_map(static fn (MessageThread $thread): int => (int) $thread->id, $shown),
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );

            // A DQL UPDATE writes past the unit of work, so every one of these
            // entities is still sitting in the identity map saying listedAt is
            // null. That is not a test artefact: a client catching up sends
            // Thread/set and Thread/get in ONE request -- retire the markers
            // for the page it drew, then read the page back -- and Thread/get
            // resolves through the same EntityManager. Without this it would
            // answer `isNew: true` for the conversations this call had just
            // retired, and the client would send the retirement again on every
            // refresh for as long as the window lasted.
            foreach ($shown as $thread) {
                $this->entityManager->refresh($thread);
            }
        }

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
     * @return bool whether this thread's New marker should be retired
     *
     * @throws MethodException when the patch names an unsettable property
     */
    private function applyPatch(MessageThread $thread, mixed $patch): bool
    {
        if (false === is_array($patch)) {
            throw new MethodException('invalidPatch', 'Each update must be an object.');
        }

        foreach (array_keys($patch) as $property) {
            if (false === in_array($property, ['snoozedUntil', 'isNew'], true)) {
                throw new MethodException(
                    'invalidProperties',
                    sprintf('"%s" is not a settable Thread property.', (string) $property),
                );
            }
        }

        $shown = false;

        if (true === array_key_exists('isNew', $patch)) {
            $this->assertRetirable($patch['isNew']);

            // Claimed even when the marker has already been retired. The
            // UPDATE's own guard is what makes a repeat a no-op, and deciding
            // here from an entity this request loaded would be reading a value
            // another client may have changed since.
            $shown = true;
        }

        if (false === array_key_exists('snoozedUntil', $patch)) {
            // An empty patch is a no-op rather than an error: it changes
            // nothing, and refusing it would make a client's "apply whatever
            // changed" loop fail on the case where nothing did.
            return $shown;
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

        return $shown;
    }

    /**
     * `isNew: false` records that this conversation has been put in front of
     * the user.
     *
     * Only false. `true` is refused rather than ignored: the column records
     * WHEN a row was displayed, so un-retiring would mean writing null over a
     * fact, and a client that could do it would be able to keep its own badges
     * alive forever. The asymmetry is the point — the marker is evidence, not
     * a preference.
     *
     * Idempotent, and that matters more here than usual: a list client will
     * send this for every row it draws, including rows it drew a minute ago.
     * markListed()'s own `listedAt IS NULL` guard means a second call changes
     * nothing rather than moving the timestamp forward, so the record keeps
     * saying when the row was FIRST shown.
     *
     * Ownership is not re-checked at the write. Unlike the browser's endpoint —
     * which takes ids straight off a JSON body and therefore needs
     * markListedForUser() — every id here has already been through
     * AccountResolver and findByAccountAndIds, so the account gate was passed
     * before this was reached.
     */
    private function assertRetirable(mixed $value): void
    {
        if (false !== $value) {
            throw new MethodException(
                'invalidProperties',
                '"isNew" can only be set to false, which records that the conversation has been shown.',
            );
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
