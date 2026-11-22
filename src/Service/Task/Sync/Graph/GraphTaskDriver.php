<?php

declare(strict_types=1);

namespace App\Service\Task\Sync\Graph;

use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\DTO\Task\RemoteTaskList;
use App\Domain\DTO\Task\RemoteTaskWriteResult;
use App\Domain\DTO\Task\TaskChangeSet;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Exception\CalendarResyncRequiredException;
use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Exception\CalendarSyncThrottledException;
use App\Domain\Exception\OAuthGrantRevokedException;
use App\Domain\Interface\TaskSyncDriverInterface;
use App\Entity\Mail\Account;
use App\Entity\Task\Task;
use App\Entity\Task\TaskList;
use App\Service\OAuth\OAuthTokenManager;
use App\Service\User\UserTimezoneResolver;
use DateTimeImmutable;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Microsoft To Do lists behind a Microsoft mail account, over Microsoft Graph.
 *
 * Rides the mail account's grant, as GraphCalendarSyncDriver does: Tasks.ReadWrite
 * is asked for with the calendars (MailProvider::taskScopes()), so there is no
 * connection of its own. supports() also turns down an account whose recorded
 * grant is known to lack it, so the subscribe dialog does not offer an account that
 * would only answer 403.
 *
 * ── What Microsoft does, tried rather than read ───────────────────────────
 *
 * Each of these was tried against a real account; the reference says otherwise
 * or nothing.
 *
 * - **A repeating task moves on by itself.** Completing one sends the same id to
 *   its next date and adds a finished copy under a new id. TaskWriter leaves such
 *   a list's repeats alone for that reason (TaskList::remoteAdvancesRepeats()),
 *   and the copy arrives with the next pull like any other task.
 * - **Moving a repeating task's due date leaves an extra copy** — see
 *   GraphTaskMapper::forUpdate(), which is why an update can be two PATCHes.
 * - **Steps are in the delta.** A step added or ticked changes its task, and
 *   `$expand=checklistItems` is carried by the delta link, so one feed has both.
 * - **Files do not go up.** A taskFileAttachment answered 500 every time, so a
 *   file kept on a task here stays here, and the remote's are left alone.
 *
 * ── Writes ────────────────────────────────────────────────────────────────
 *
 * A write returns no etag, on purpose. The answer to a PATCH is the task as the
 * server left it, which is not always what was sent: a completed repeating task
 * comes back open on its next date. With no etag stored, the next pull compares
 * nothing and takes the server's copy, rather than skipping it as already known
 * and leaving the task ticked here while it is open everywhere else.
 *
 * The failure classes are GraphCalendarSyncDriver's, for that driver's reasons.
 */
final readonly class GraphTaskDriver implements TaskSyncDriverInterface
{
    private const string LISTS = 'https://graph.microsoft.com/v1.0/me/todo/lists';

    /** Flagged mail is a list To Do makes from Outlook flags, which plMail has as flags already. */
    private const string FLAGGED_EMAILS = 'flaggedemails';

    /** See GraphCalendarSyncDriver::AUTHORIZATION_CODES. */
    private const array AUTHORIZATION_CODES = [
        'erroraccessdenied',
        'accessdenied',
        'authorization_requestdenied',
        'invalidauthenticationtoken',
        'insufficientscope',
        'unauthorizedaccess',
    ];

    private const string RECONNECT_HINT = 'Microsoft refused access to To Do for this account. '
        . 'Reconnect the account and allow tasks.';

    public function __construct(
        #[Target('graph.client')]
        private HttpClientInterface  $httpClient,
        private OAuthTokenManager    $tokens,
        private GraphTaskMapper      $mapper,
        private UserTimezoneResolver $timezones,
        private ClockInterface       $clock,
    ) {}

    public function supports(CalendarSource $source): bool
    {
        $account = $source->account;

        return MailProvider::Microsoft === $source->mailProvider()
            && null !== $account
            && false !== MailProvider::Microsoft->grantsTaskAccess($account->oauthGrantedScopes);
    }

    public function discover(CalendarSource $source): array
    {
        $account = $source->account ?? throw new CalendarSyncPermanentException('This source is not a Microsoft account.');
        $lists   = [];

        foreach ($this->collect($account, self::LISTS, [], 'listing the To Do lists')['items'] as $list) {
            $id   = trim((string) ($list['id'] ?? ''));
            $kind = strtolower((string) ($list['wellknownListName'] ?? ''));

            if ('' === $id || self::FLAGGED_EMAILS === $kind) {
                continue;
            }

            $lists[] = new RemoteTaskList(
                remoteId:  $id,
                name:      trim((string) ($list['displayName'] ?? '')) ?: 'To Do',
                isDefault: 'defaultlist' === $kind,
            );
        }

        return $lists;
    }

    public function pull(TaskList $list, ?string $syncToken): TaskChangeSet
    {
        $account = $this->accountOf($list);

        // A delta link carries the expand it was opened with, so a resumed read
        // presents the link and nothing else.
        if (null !== $syncToken && '' !== $syncToken) {
            $page = $this->collect($account, $syncToken, [], 'reading To Do changes');
        } else {
            $page = $this->collect(
                $account,
                sprintf('%s/%s/tasks/delta', self::LISTS, rawurlencode($this->remoteIdOf($list))),
                ['$expand' => 'checklistItems'],
                'reading the To Do list',
            );
        }

        $zone  = $this->timezones->resolve($list->usr);
        $tasks = [];

        foreach ($page['items'] as $item) {
            array_push($tasks, ...$this->mapper->fromGraph($item, $zone));
        }

        return new TaskChangeSet($tasks, $page['deltaLink']);
    }

    public function push(TaskList $list, Task $task): RemoteTaskWriteResult
    {
        $account = $this->accountOf($list);

        if (null !== $task->parent) {
            return $this->pushStep($account, $list, $task);
        }

        $zone  = $this->timezones->resolve($list->usr);
        $today = $this->clock->now();

        if (null === $task->remoteId) {
            $created = $this->call($account, 'POST', $this->tasksUrl($list), [
                'json' => $this->mapper->forCreate($task, $zone, $today),
            ], 'creating a task');

            return $this->resultOf($created, null);
        }

        $answer = null;

        foreach ($this->mapper->forUpdate($task, $task->remotePayload, $zone, $today) as $body) {
            $answer = $this->call($account, 'PATCH', $this->taskUrl($list, $task->remoteId), ['json' => $body], 'updating a task');
        }

        return null === $answer
            ? new RemoteTaskWriteResult($task->remoteId, null, $task->remotePayload)
            : $this->resultOf($answer, $task->remoteId);
    }

    public function delete(TaskList $list, Task $task): void
    {
        if (null === $task->remoteId) {
            return;
        }

        $url = null === $task->parent
            ? $this->taskUrl($list, $task->remoteId)
            : $this->stepUrl($list, $task->remoteId);

        // Already gone is the outcome asked for, per the interface.
        $this->call($this->accountOf($list), 'DELETE', $url, [], 'deleting a task', [404, 410]);
    }

    // ── Steps ────────────────────────────────────────────────────────────────

    /**
     * A step is a checklistItem of its task, so the task has to be at Microsoft
     * first. The pusher sends parents before their steps; a parent still without an
     * id here failed on its own, and this waits for it rather than being lost.
     */
    private function pushStep(Account $account, TaskList $list, Task $step): RemoteTaskWriteResult
    {
        $parentId = $step->parent?->remoteId;

        if (null === $parentId) {
            throw new CalendarSyncException('A step cannot go to Microsoft before its task has.');
        }

        $body = $this->mapper->forStep($step);

        if (null === $step->remoteId) {
            $created = $this->call(
                $account,
                'POST',
                $this->taskUrl($list, $parentId) . '/checklistItems',
                ['json' => $body],
                'adding a step',
            );

            $id = trim((string) ($created['id'] ?? ''));

            if ('' === $id) {
                throw new CalendarSyncException('Microsoft accepted a step without saying what it is called.');
            }

            return new RemoteTaskWriteResult($parentId . GraphTaskMapper::STEP_SEPARATOR . $id, null, $this->encode($created));
        }

        $updated = $this->call($account, 'PATCH', $this->stepUrl($list, $step->remoteId), ['json' => $body], 'updating a step');

        return new RemoteTaskWriteResult($step->remoteId, null, $this->encode($updated));
    }

    private function stepUrl(TaskList $list, string $remoteId): string
    {
        [$taskId, $itemId] = array_pad(explode(GraphTaskMapper::STEP_SEPARATOR, $remoteId, 2), 2, '');

        return $this->taskUrl($list, $taskId) . '/checklistItems/' . rawurlencode($itemId);
    }

    // ── Transport ────────────────────────────────────────────────────────────

    private function tasksUrl(TaskList $list): string
    {
        return sprintf('%s/%s/tasks', self::LISTS, rawurlencode($this->remoteIdOf($list)));
    }

    private function taskUrl(TaskList $list, string $taskId): string
    {
        return $this->tasksUrl($list) . '/' . rawurlencode($taskId);
    }

    /** @param array<string,mixed> $answer */
    private function resultOf(array $answer, ?string $knownId): RemoteTaskWriteResult
    {
        $id = trim((string) ($answer['id'] ?? $knownId ?? ''));

        if ('' === $id) {
            throw new CalendarSyncException('Microsoft accepted a task without saying what it is called.');
        }

        // No etag: see the class docblock.
        return new RemoteTaskWriteResult($id, null, $this->encode($answer));
    }

    /**
     * Every page of a listing, and the delta link at its end. A page may be empty
     * and carry only the link.
     *
     * @param array<string,string> $query sent with the first request only
     *
     * @return array{items: list<array<string,mixed>>, deltaLink: string|null}
     */
    private function collect(Account $account, string $url, array $query, string $what): array
    {
        $items     = [];
        $deltaLink = null;
        $next      = $url;
        $options   = [] === $query ? [] : ['query' => $query];

        while (null !== $next) {
            $body    = $this->call($account, 'GET', $next, $options, $what);
            $options = [];

            foreach (is_array($body['value'] ?? null) ? $body['value'] : [] as $item) {
                if (true === is_array($item)) {
                    $items[] = $item;
                }
            }

            $link = $body['@odata.nextLink'] ?? null;
            $next = is_string($link) && '' !== $link ? $link : null;

            if (is_string($body['@odata.deltaLink'] ?? null) && '' !== $body['@odata.deltaLink']) {
                $deltaLink = $body['@odata.deltaLink'];
            }
        }

        return ['items' => $items, 'deltaLink' => $deltaLink];
    }

    /**
     * @param array<string,mixed> $options
     * @param list<int>           $tolerate statuses that are an answer, not a failure
     *
     * @return array<string,mixed>
     */
    private function call(Account $account, string $method, string $url, array $options, string $what, array $tolerate = []): array
    {
        $options['auth_bearer'] = $this->accessToken($account);

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status   = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new CalendarSyncException(sprintf('Microsoft Graph could not be reached while %s.', $what), 0, $e);
        }

        if (true === in_array($status, $tolerate, true) || 204 === $status) {
            return [];
        }

        if (200 > $status || 300 <= $status) {
            throw $this->translate($response, $status, $what);
        }

        try {
            return $response->toArray(false);
        } catch (DecodingExceptionInterface | TransportExceptionInterface $e) {
            throw new CalendarSyncException(sprintf('Microsoft Graph answered something that is not JSON while %s.', $what), $status, $e);
        }
    }

    /** See GraphCalendarSyncDriver::accessToken(). */
    private function accessToken(Account $account): string
    {
        try {
            return $this->tokens->getValidAccessToken($account);
        } catch (OAuthGrantRevokedException $e) {
            throw new CalendarSyncPermanentException(
                'Microsoft would not renew the sign-in for this account. Reconnect it in the account settings.',
                401,
                $e,
            );
        } catch (\Throwable $e) {
            throw new CalendarSyncException('Microsoft could not renew the sign-in for this account.', 401, $e);
        }
    }

    /** See GraphCalendarSyncDriver::translate(); the message never carries the URL, which holds the delta token. */
    private function translate(ResponseInterface $response, int $status, string $what): CalendarSyncException
    {
        try {
            $decoded = json_decode($response->getContent(false), true);
        } catch (TransportExceptionInterface) {
            $decoded = null;
        }

        $error   = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $code    = trim((string) ($error['code'] ?? ''));
        $message = mb_substr(trim((string) ($error['message'] ?? '')), 0, 200);
        $reason  = '' === $code ? ('' === $message ? 'no reason given' : $message) : trim($code . ': ' . $message, ': ');

        if (410 === $status) {
            return new CalendarResyncRequiredException('Microsoft can no longer continue from the stored sync position, so the list is being read again from scratch.');
        }

        if (429 === $status || 503 === $status) {
            $after = $response->getHeaders(false)['retry-after'][0] ?? null;

            return new CalendarSyncThrottledException(
                sprintf('Microsoft Graph is rate-limiting this account while %s.', $what),
                $status,
                true === ctype_digit((string) $after) ? (int) $after : null,
            );
        }

        $denied = in_array(strtolower($code), self::AUTHORIZATION_CODES, true)
            || str_contains(strtolower($message), 'access is denied');

        if (401 === $status || (403 === $status && $denied)) {
            return new CalendarSyncPermanentException(self::RECONNECT_HINT, $status);
        }

        if (400 === $status || 404 === $status) {
            return new CalendarSyncPermanentException(sprintf('Microsoft Graph refused this request while %s: %s', $what, $reason), $status);
        }

        return new CalendarSyncException(sprintf('Microsoft Graph failed while %s (%d): %s', $what, $status, $reason), $status);
    }

    private function accountOf(TaskList $list): Account
    {
        return $list->account ?? throw new CalendarSyncPermanentException(sprintf('Task list #%d is not attached to a Microsoft account.', (int) $list->id));
    }

    private function remoteIdOf(TaskList $list): string
    {
        return $list->remoteId ?? throw new CalendarSyncPermanentException(sprintf('Task list #%d has no list at Microsoft behind it.', (int) $list->id));
    }

    /** @param array<string,mixed> $item */
    private function encode(array $item): string
    {
        return json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
