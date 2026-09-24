<?php

declare(strict_types=1);

namespace App\Service\System\Update;

use App\Domain\DTO\System\SourceComparison;
use App\Domain\DTO\System\UpdateStatus;
use App\Domain\Exception\UpdateCheckFailedException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * How two builds relate, asked of the repository they were built from.
 *
 * Two different commits do not make an update. A main build running while the
 * installation follows releases is NEWER than the latest release, and "a new
 * release is available" would be announcing an older build. Only the history
 * can say which way the difference runs, so this asks it: GitHub's compare
 * answers `ahead` when the channel has commits the running build lacks, and
 * lists them, which is also the "what's new" the admin page shows.
 *
 * GitHub only, because it is the one host whose history can be asked without
 * an account. An image that names any other source gets null, and the checker
 * falls back to what the versions alone can say.
 */
final readonly class SourceHistory
{
    private const array LIMITS = ['timeout' => 10, 'max_duration' => 20];

    public function __construct(
        private HttpClientInterface $http,
    ) {
    }

    /**
     * Null when the source is not a GitHub repository, or when it does not know
     * the running commit (a build of a fork, or of a local branch): then the
     * two builds have no shared history to compare.
     *
     * @throws UpdateCheckFailedException when GitHub cannot be reached or refuses
     */
    public function compare(string $source, string $base, string $head): ?SourceComparison
    {
        if (1 !== preg_match('#^https://github\.com/([\w.-]+)/([\w.-]+?)(?:\.git)?/?$#', $source, $repo)) {
            return null;
        }

        try {
            $response = $this->http->request(
                'GET',
                sprintf('https://api.github.com/repos/%s/%s/compare/%s...%s', $repo[1], $repo[2], rawurlencode($base), rawurlencode($head)),
                [
                    'headers' => [
                        'Accept'               => 'application/vnd.github+json',
                        'X-GitHub-Api-Version' => '2022-11-28',
                        // GitHub refuses a request that does not name itself.
                        'User-Agent'           => 'plMail update check',
                    ],
                ] + self::LIMITS,
            );

            $status = $response->getStatusCode();

            if (404 === $status || 422 === $status) {
                return null;
            }

            if (200 !== $status) {
                // 403 with a zero remaining count is the unauthenticated rate
                // limit, sixty an hour per address. Said as such, because the
                // fix is to wait rather than to look for a fault.
                $remaining = $response->getHeaders(false)['x-ratelimit-remaining'][0] ?? null;

                throw new UpdateCheckFailedException('0' === $remaining
                    ? 'api.github.com is rate-limiting this address; the next hourly check will try again'
                    : sprintf('api.github.com answered HTTP %d', $status));
            }

            $body = $response->toArray();
        } catch (ExceptionInterface $e) {
            throw new UpdateCheckFailedException(sprintf('api.github.com could not be reached: %s', $e->getMessage()), 0, $e);
        }

        return new SourceComparison(
            (string) ($body['status'] ?? ''),
            (int) ($body['ahead_by'] ?? 0),
            $this->changes($body['commits'] ?? []),
            is_string($body['html_url'] ?? null) ? $body['html_url'] : null,
        );
    }

    /**
     * The subjects of the newest commits, newest first. GitHub lists them
     * oldest first; the page wants the latest at the top.
     *
     * @param mixed $commits
     *
     * @return list<array{sha: string, subject: string}>
     */
    private function changes(mixed $commits): array
    {
        $changes = [];

        foreach (array_reverse(is_array($commits) ? $commits : []) as $commit) {
            $sha     = $commit['sha'] ?? null;
            $message = $commit['commit']['message'] ?? null;

            if (false === is_string($sha) || false === is_string($message)) {
                continue;
            }

            $changes[] = ['sha' => $sha, 'subject' => strtok($message, "\n") ?: $message];

            if (UpdateStatus::CHANGES_KEPT === count($changes)) {
                break;
            }
        }

        return $changes;
    }
}
