<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Rule\MailRule;
use App\Entity\User\User;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\Rule\MailRuleRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Undo for the actions that file mail: archive, label, move to.
 *
 * All three end with a row gone from the list or a chip changed on it, and a
 * toast saying so. The Undo in that toast has to put back **exactly the labels
 * each message had** — not "the inverse of the action", which is a different
 * thing as soon as the selection is mixed. Un-archiving five conversations by
 * calling restore() on them is right for the four that were in the Inbox and
 * wrong for the one that was already archived and happened to be ticked.
 *
 * So the state is remembered, not the action. remember() is called before the
 * mutation and writes down each message's label ids; restore() reads the
 * difference between that and what the message carries now, and closes it.
 *
 * ## Remembered in the session, not sent to the browser
 *
 * The toast carries a token and nothing else. The snapshot stays on the
 * server, in the session of the person who made the change, because an undo
 * that accepted a label list from the client would be a route for writing any
 * label set onto any message. A handful are kept, newest last: the toast is on
 * screen for a few seconds and nothing can reach an older one.
 *
 * ## Closing the difference goes through the same operations
 *
 * Where the mail lives is put right first, with ThreadStatusUpdater::move()
 * and its siblings, because those are what tell a provider to move something —
 * an undo that only rewrote the rows would leave the conversation in the inbox
 * here and in the archive on the server until the next sync moved it back out.
 * The user's own labels follow through applyLabel(), and whatever still
 * differs after that is local bookkeeping, set directly. See resetLabels().
 */
final readonly class StatusUndoService
{
    private const string SESSION_KEY = 'mail.status_undo';

    /**
     * How many snapshots a session holds.
     *
     * More than one, because two actions in quick succession leave two toasts
     * on screen and both Undo buttons should work. Not many, because each is a
     * selection's worth of ids kept in the session for the sake of a button
     * that is gone in seconds.
     */
    private const int KEPT = 5;

    /**
     * Where a message can be said to live, most decisive first.
     *
     * The order restore() reads a label set in: something in the bin is in the
     * bin whatever else it wears, and Archive is last because it is what mail
     * is in when it is nowhere else.
     *
     * @var list<LabelRole>
     */
    private const array PLACES_FIRST = [LabelRole::Trash, LabelRole::Spam, LabelRole::Inbox];

    public function __construct(
        private RequestStack           $requestStack,
        private EntityManagerInterface $em,
        private MessageRepository      $messages,
        private LabelRepository        $labels,
        private ThreadStatusUpdater    $status,
        private MailRuleRepository     $rules,
    ) {
    }

    /**
     * Write down what these messages carry now, and answer with the token an
     * undo is asked for by.
     *
     * Called BEFORE the mutation. A snapshot taken afterwards is a record of
     * the thing to be undone.
     *
     * @param iterable<Message> $messages
     */
    public function remember(iterable $messages): string
    {
        $snapshot = ['messages' => [], 'threads' => []];

        foreach ($messages as $message) {
            $snapshot['messages'][(int) $message->id] = array_values(
                $message->labels->map(static fn (Label $label): int => (int) $label->id)->toArray(),
            );

            // A move into the bin ends a snooze (ThreadStatusUpdater::move()),
            // and putting the Snoozed label back without its wake time would
            // leave a conversation waiting for a moment that never comes.
            $thread = $message->thread;

            if (null !== $thread) {
                $snapshot['threads'][(int) $thread->id] = $thread->snoozedUntil?->format(DATE_ATOM);
            }
        }

        $token   = bin2hex(random_bytes(16));
        $session = $this->requestStack->getSession();

        $kept         = (array) $session->get(self::SESSION_KEY, []);
        $kept[$token] = $snapshot;

        $session->set(self::SESSION_KEY, array_slice($kept, -self::KEPT, null, true));

        return $token;
    }

    /**
     * Make an Undo of this action take a filter back with it.
     *
     * For the spam button, whose one click can both move a conversation and
     * create the rule that does the same to the sender's next mail. Undoing
     * half of that would leave a filter the person has just said they did not
     * mean. Called only for a rule the action itself created.
     *
     * A token that is no longer kept is left alone: there is nothing to attach
     * to, and the rule is still listed under Settings → Filters.
     */
    public function alsoRemove(string $token, MailRule $rule): void
    {
        $session = $this->requestStack->getSession();
        $kept    = (array) $session->get(self::SESSION_KEY, []);

        if (false === isset($kept[$token]) || null === $rule->id) {
            return;
        }

        $kept[$token]['rule'] = $rule->id;

        $session->set(self::SESSION_KEY, $kept);
    }

    /**
     * Put back what remember() wrote down.
     *
     * Single use: the snapshot is taken out of the session before anything is
     * written, so a double click cannot run it twice and a second undo of the
     * same action answers "too late" instead of quietly re-applying an old
     * state over whatever happened since.
     *
     * @return int|null how many messages were put back, or null when the token
     *                  names nothing — expired, used, or never issued
     */
    public function restore(string $token, User $user): ?int
    {
        $session = $this->requestStack->getSession();
        $kept    = (array) $session->get(self::SESSION_KEY, []);

        if (false === isset($kept[$token])) {
            return null;
        }

        $snapshot = $kept[$token];

        unset($kept[$token]);
        $session->set(self::SESSION_KEY, $kept);

        $this->removeRule($snapshot['rule'] ?? null, $user);

        $messages = [];

        foreach ($this->messages->findBy(['id' => array_keys($snapshot['messages'])]) as $message) {
            // The snapshot came out of this user's own session, so these are
            // theirs by construction — checked anyway, because it is the last
            // thing before a write and a session is not a schema.
            if ($message->account->usr->id === $user->id) {
                $messages[] = $message;
            }
        }

        if ([] === $messages) {
            return 0;
        }

        $before = $this->labelsBefore($snapshot['messages'], $user);

        $this->em->wrapInTransaction(function () use ($messages, $before, $snapshot): void {
            $this->returnToPlaces($messages, $before);
            $this->returnUserLabels($messages, $before);

            $this->wakeTimes($messages, $snapshot['threads']);
            $this->status->resetLabels($messages, $before);
        });

        return count($messages);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The filter alsoRemove() attached, if there was one and it is still the
     * user's. Gone already — deleted by hand in the seconds between — is fine.
     */
    private function removeRule(mixed $id, User $user): void
    {
        if (false === is_int($id)) {
            return;
        }

        $rule = $this->rules->find($id);

        if (null === $rule || $rule->usr?->id !== $user->id) {
            return;
        }

        $this->em->remove($rule);
        $this->em->flush();
    }

    /**
     * Move every message that is not where it was back to where it was.
     *
     * Grouped by account and destination, because ThreadStatusUpdater resolves
     * folders from the first message it is handed.
     *
     * @param list<Message>           $messages
     * @param array<int, list<Label>> $before
     */
    private function returnToPlaces(array $messages, array $before): void
    {
        $returning = [];
        $placeless = [];

        foreach ($messages as $message) {
            $account = $message->account;
            $was     = $this->placeAmong($before[(int) $message->id] ?? [], $account);
            $now     = $this->placeAmong($message->labels->toArray(), $account);

            if ($was === $now) {
                continue;
            }

            if (null === $was) {
                $placeless[(int) $account->id][] = $message;

                continue;
            }

            $key = sprintf('%d:%d', $account->id, $was->id);

            $returning[$key] ??= ['place' => $was, 'messages' => []];
            $returning[$key]['messages'][] = $message;
        }

        foreach ($returning as $group) {
            // move() and not a label swap: it sends Inbox to restore(), Archive
            // to archive() and Trash to trash(), which is what makes this a
            // move at the provider as well as here.
            $this->status->move($group['messages'], $group['place']);
        }

        // Mail that was in none of these places and is in one now — a Gmail
        // conversation that wore only a custom label and was moved to the
        // Inbox. There is no "put it nowhere" operation, so it is taken out of
        // the bin if it is in it and then out of the inbox, which is what
        // tells the provider; resetLabels() drops the Archive label that
        // leaves behind.
        foreach ($placeless as $group) {
            $discarded = array_values(array_filter($group, fn (Message $message): bool => $this->carriesRole($message, LabelRole::Trash) || $this->carriesRole($message, LabelRole::Spam)));

            if ([] !== $discarded) {
                $this->status->restore($discarded);
            }

            $inInbox = array_values(array_filter($group, fn (Message $message): bool => $this->carriesRole($message, LabelRole::Inbox)));

            if ([] !== $inInbox) {
                $this->status->archive($inInbox);
            }
        }
    }

    /**
     * Re-attach and detach the user's own labels, through the path that tells
     * Gmail and Exchange about them.
     *
     * Attached before anything is detached, for the reason every label move in
     * ThreadStatusUpdater gives: on plain IMAP a detach picks the folder the
     * mail goes to from the labels still on it.
     *
     * @param list<Message>           $messages
     * @param array<int, list<Label>> $before
     */
    private function returnUserLabels(array $messages, array $before): void
    {
        $attach = [];
        $detach = [];

        foreach ($messages as $message) {
            $was = $before[(int) $message->id] ?? [];
            $now = $message->labels->toArray();

            foreach ($was as $label) {
                if (null === $label->role && false === in_array($label, $now, true)) {
                    $this->group($attach, $message, $label);
                }
            }

            foreach ($now as $label) {
                if (null === $label->role && false === in_array($label, $was, true)) {
                    $this->group($detach, $message, $label);
                }
            }
        }

        foreach ($attach as $group) {
            $this->status->applyLabel($group['messages'], $group['label'], true);
        }

        foreach ($detach as $group) {
            $this->status->applyLabel($group['messages'], $group['label'], false);
        }
    }

    /**
     * @param array<string, array{label: Label, messages: list<Message>}> $groups
     */
    private function group(array &$groups, Message $message, Label $label): void
    {
        $key = sprintf('%d:%d', $message->account->id, $label->id);

        $groups[$key] ??= ['label' => $label, 'messages' => []];
        $groups[$key]['messages'][] = $message;
    }

    /**
     * @param list<Message>            $messages
     * @param array<int, string|null> $wakeTimes keyed by thread id
     */
    private function wakeTimes(array $messages, array $wakeTimes): void
    {
        foreach ($messages as $message) {
            $thread = $message->thread;

            if (null === $thread || false === array_key_exists((int) $thread->id, $wakeTimes)) {
                continue;
            }

            $until = $wakeTimes[(int) $thread->id];

            $thread->snoozedUntil = null === $until ? null : new DateTimeImmutable($until);
        }
    }

    /**
     * The snapshot's ids as labels, per message.
     *
     * A label deleted since the snapshot was taken is simply absent — there is
     * nothing to put back — and one that is not this user's is dropped for the
     * reason restore() checks the messages.
     *
     * @param array<int, list<int>> $idsByMessage
     *
     * @return array<int, list<Label>>
     */
    private function labelsBefore(array $idsByMessage, User $user): array
    {
        $ids = array_values(array_unique(array_merge(...array_values($idsByMessage))));

        $byId = [];

        foreach ($this->labels->findBy(['id' => $ids]) as $label) {
            if ($label->usr?->id === $user->id) {
                $byId[(int) $label->id] = $label;
            }
        }

        $before = [];

        foreach ($idsByMessage as $messageId => $labelIds) {
            $before[(int) $messageId] = array_values(array_filter(array_map(
                static fn (int $id): ?Label => $byId[$id] ?? null,
                $labelIds,
            )));
        }

        return $before;
    }

    /**
     * The one label in a set that says where the mail is, or null.
     *
     * A custom label counts only when it is a real folder on this account —
     * the distinction ThreadStatusUpdater::locationLabelsOf() draws, and for
     * the same reason: a tag is not somewhere mail can be moved back to.
     *
     * @param list<Label> $labels
     */
    private function placeAmong(array $labels, Account $account): ?Label
    {
        foreach (self::PLACES_FIRST as $role) {
            foreach ($labels as $label) {
                if ($role === $label->role) {
                    return $label;
                }
            }
        }

        foreach ($labels as $label) {
            if (null === $label->role && null !== $label->bindingFor($account)?->mailbox) {
                return $label;
            }
        }

        foreach ($labels as $label) {
            if (LabelRole::Archive === $label->role) {
                return $label;
            }
        }

        return null;
    }

    private function carriesRole(Message $message, LabelRole $role): bool
    {
        foreach ($message->labels as $label) {
            if ($role === $label->role) {
                return true;
            }
        }

        return false;
    }
}
