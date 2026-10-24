<?php

declare(strict_types=1);

namespace App\Command\Diagnostics;

use App\Domain\Enum\Account\AuthType;
use App\Entity\Mail\Account;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Gmail\GmailQuotaPacer;
use App\Service\OAuth\OAuthTokenManager;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetch a known number of messages from Gmail in a known way, in a minute of
 * its own, so that what Google charged for them can be read off its meter.
 *
 * WHY THIS EXISTS
 * ───────────────
 * Google's quota table says a messages.get costs 20 units. An import paced on
 * that was refused with 3,240 units counted of a 6,000 limit, and the
 * project's own "Units per minute" figure stood near 6,000 for about a hundred
 * fetches — three times what the table says (#90). The traffic showed no other
 * call to blame, so the question is what about the fetch is charged that way,
 * and that cannot be answered by reading: Google reports no cost per request,
 * only the minute's total in the Cloud console.
 *
 * So this makes the minute's total mean something. Each run of a mode is the
 * same messages fetched one way — through the batch endpoint as an import
 * does it, or singly, in each of the formats — started just after a minute
 * begins and followed by a minute of nothing. The console's chart for that
 * minute, over the number of requests, is the cost of one.
 *
 * WHAT IT DOES TO THE ACCOUNT
 * ───────────────────────────
 * Nothing is stored and nothing in the mailbox is changed; the responses are
 * read for their status and thrown away. The ids come from the database, so
 * finding them costs no quota.
 *
 * The account's bulk fetching IS held for as long as the probe runs — an
 * import going on beside it would be the same minute's total and the reading
 * would mean nothing. Held by the pacer's own means (see
 * GmailQuotaPacer::hold()), so the import's jobs put themselves back and
 * carry on afterwards. What is not held is small: an ordinary sync is three
 * units, and whatever else is signed in to the same Google account through
 * the same Cloud project is not this application's to hold. A reading that
 * looks wrong is worth taking twice.
 */
#[AsCommand(
    name: 'app:gmail:quota-probe',
    description: 'Fetch a fixed number of Gmail messages one way per minute, to read what Google charges for each off its quota meter',
)]
final class GmailQuotaProbeCommand extends Command
{
    private const string BASE  = 'https://gmail.googleapis.com/gmail/v1/users/me';
    private const string BATCH = 'https://www.googleapis.com/batch/gmail/v1';

    /** What Google's quota table says a messages.get costs, to set the meter's reading beside. */
    private const int DOCUMENTED_UNITS = 20;

    /** How many messages.get go into one batch request. */
    private const int MESSAGES_PER_REQUEST = 10;

    /**
     * Each way of fetching a message that is worth telling apart, and how.
     *
     * `batch-full` is what an import does. The rest take it apart: the same
     * format without the batch endpoint, then the other formats.
     */
    private const array MODES = [
        'batch-full'      => ['batch' => true,  'format' => 'full'],
        'single-full'     => ['batch' => false, 'format' => 'full'],
        'single-metadata' => ['batch' => false, 'format' => 'metadata'],
        'single-minimal'  => ['batch' => false, 'format' => 'minimal'],
        'single-raw'      => ['batch' => false, 'format' => 'raw'],
    ];

    public function __construct(
        private readonly AccountRepository   $accounts,
        private readonly MessageRepository   $messages,
        private readonly OAuthTokenManager   $tokens,
        private readonly HttpClientInterface $http,
        private readonly GmailQuotaPacer     $pacer,
        private readonly ClockInterface      $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('account', InputArgument::OPTIONAL, 'The Gmail account to fetch from, by id or address; leave it out to be shown the list')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'One of ' . implode(', ', array_keys(self::MODES)) . ', or all', 'all')
            ->addOption('count', null, InputOption::VALUE_REQUIRED, 'How many messages each mode fetches', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $account = $this->accountFor($input, $io);

        if (null === $account) {
            return Command::FAILURE;
        }

        $mode  = (string) $input->getOption('mode');
        $modes = 'all' === $mode ? array_keys(self::MODES) : [$mode];

        if ([] !== array_diff($modes, array_keys(self::MODES))) {
            $io->error(sprintf('Unknown mode "%s". Use one of: %s, all.', $mode, implode(', ', array_keys(self::MODES))));

            return Command::FAILURE;
        }

        $count = max(1, min(100, (int) $input->getOption('count')));
        $ids   = $this->messages->findSomeGmailIdsForAccount($account, $count);

        if ([] === $ids) {
            $io->error('This account has no stored Gmail messages to fetch again. Let an import bring some in first.');

            return Command::FAILURE;
        }

        // Two minutes a mode — the one it runs in and a quiet one after — and
        // up to two before the first, for what was already under way to end.
        $seconds = 120 * count($modes) + 120;

        $this->pacer->hold($account, $seconds);

        $io->title(sprintf('Gmail quota probe: %s', $account->displayAddress));
        $io->text([
            sprintf('%d messages a mode, %d mode(s), about %d minutes.', count($ids), count($modes), intdiv($seconds, 60)),
            'This account\'s import and bulk fetching are held until it is over.',
            'Waiting for a quiet minute to start in…',
        ]);

        // What was in flight when the hold began finishes inside this minute.
        $this->clock->sleep(60);

        $rows = [];

        foreach ($modes as $name) {
            $this->waitForTheNextMinute();

            $startedAt = $this->clock->now();
            $result    = $this->fetch($account, $ids, self::MODES[$name]);

            $rows[] = [
                $name,
                $startedAt->setTimezone(new DateTimeZone('UTC'))->format('H:i') . ' UTC',
                (string) $result['requests'],
                (string) $result['ok'],
                (string) $result['refused'],
                (string) $result['other'],
                number_format($result['requests'] * self::DOCUMENTED_UNITS),
                '' === $result['firstError'] ? '' : mb_strimwidth($result['firstError'], 0, 60, '…'),
            ];

            $io->text(sprintf(
                '%s  %s  %d requests, %d ok, %d refused',
                $startedAt->setTimezone(new DateTimeZone('UTC'))->format('H:i:s'),
                $name,
                $result['requests'],
                $result['ok'],
                $result['refused'],
            ));

            // The minute after stays empty, so each reading stands alone.
            $this->clock->sleep(60);
        }

        $this->pacer->hold($account, 0);

        $io->newLine();
        $io->table(
            ['Mode', 'Minute', 'messages.get', '200', '403/429', 'Other', 'Units by Google\'s table', 'First error'],
            $rows,
        );

        $io->text([
            'Now read what Google charged:',
            '  Google Cloud console → APIs & Services → Gmail API → Quotas & System Limits',
            '  → the "Units per minute" row → the chart icon at its right-hand end.',
            'For each minute above, the chart\'s value over the number of messages.get is the',
            'cost of one. The chart is in your local time and the minutes above are UTC.',
            'A sync of this account in the same minute adds about three units.',
        ]);

        return Command::SUCCESS;
    }

    /**
     * The account named on the command line, or one picked from the list.
     *
     * An id is not something anybody knows: the web interface shows an
     * address. So the argument takes either, and without one the command
     * lists the Gmail accounts there are — and asks, when there is somebody
     * to ask.
     */
    private function accountFor(InputInterface $input, SymfonyStyle $io): ?Account
    {
        /** @var list<Account> $gmail */
        $gmail = $this->accounts->findBy(
            ['authType' => AuthType::OAuth2->value, 'oauthProvider' => 'google', 'isActive' => true],
            ['id' => 'ASC'],
        );

        $named = $input->getArgument('account');

        if (true === is_string($named) && '' !== trim($named)) {
            $named = trim($named);

            foreach ($gmail as $account) {
                if ((string) $account->id === $named || 0 === strcasecmp((string) $account->email, $named)) {
                    return $account;
                }
            }

            $io->error(sprintf('"%s" is not an active Gmail account here.', $named));
        }

        if ([] === $gmail) {
            $io->error('There is no active Gmail account on this installation.');

            return null;
        }

        $io->table(
            ['Id', 'Address', 'Belongs to'],
            array_map(
                static fn (Account $account): array => [(string) $account->id, (string) $account->email, (string) $account->usr?->email],
                $gmail,
            ),
        );

        if (false === $input->isInteractive()) {
            $io->text('Run it again with one of these ids or addresses.');

            return null;
        }

        if (1 === count($gmail)) {
            return true === $io->confirm(sprintf('Probe %s?', $gmail[0]->email), true) ? $gmail[0] : null;
        }

        $choices = [];

        foreach ($gmail as $account) {
            $choices[(string) $account->id] = (string) $account->email;
        }

        $picked = $io->choice('Which account?', $choices);

        foreach ($gmail as $account) {
            if ((string) $account->id === (string) $picked || (string) $account->email === (string) $picked) {
                return $account;
            }
        }

        return null;
    }

    /**
     * Five seconds into the next clock minute: late enough that the minute
     * has certainly begun at Google's end too, early enough that every
     * request of a mode lands inside it.
     */
    private function waitForTheNextMinute(): void
    {
        $now  = $this->clock->now();
        $wait = 60 - (int) $now->format('s') + 5;

        $this->clock->sleep($wait);
    }

    /**
     * @param list<string>                         $ids
     * @param array{batch: bool, format: string}   $mode
     *
     * @return array{requests: int, ok: int, refused: int, other: int, firstError: string}
     */
    private function fetch(Account $account, array $ids, array $mode): array
    {
        $token  = $this->tokens->getValidAccessToken($account);
        $result = ['requests' => 0, 'ok' => 0, 'refused' => 0, 'other' => 0, 'firstError' => ''];

        if (false === $mode['batch']) {
            foreach ($ids as $id) {
                $response = $this->http->request('GET', self::BASE . '/messages/' . urlencode($id), [
                    'auth_bearer' => $token,
                    'query'       => ['format' => $mode['format']],
                ]);

                $result = $this->count($result, $response->getStatusCode(), $response->getContent(false));
            }

            return $result;
        }

        foreach (array_chunk($ids, self::MESSAGES_PER_REQUEST) as $chunk) {
            $boundary = 'plmail_probe_' . bin2hex(random_bytes(8));
            $parts    = [];

            foreach ($chunk as $id) {
                $parts[] = implode("\r\n", [
                    '--' . $boundary,
                    'Content-Type: application/http',
                    'Content-Id: <' . $id . '>',
                    '',
                    'GET /gmail/v1/users/me/messages/' . urlencode($id) . '?format=' . $mode['format'] . ' HTTP/1.1',
                    'Host: gmail.googleapis.com',
                    '',
                    '',
                ]);
            }

            $response = $this->http->request('POST', self::BATCH, [
                'auth_bearer' => $token,
                'headers'     => ['Content-Type' => 'multipart/mixed; boundary="' . $boundary . '"'],
                'body'        => implode('', $parts) . '--' . $boundary . '--',
            ]);

            $status = $response->getStatusCode();
            $body   = $response->getContent(false);

            if (200 !== $status) {
                // The request as a whole was turned away: every message in it.
                foreach ($chunk as $ignored) {
                    $result = $this->count($result, $status, $body);
                }

                continue;
            }

            // One status line per message inside the multipart answer.
            preg_match_all('/^HTTP\/[\d.]+\s+(\d{3})/m', $body, $found);

            foreach ($found[1] as $inner) {
                $result = $this->count($result, (int) $inner, 200 === (int) $inner ? '' : $body);
            }
        }

        return $result;
    }

    /**
     * @param array{requests: int, ok: int, refused: int, other: int, firstError: string} $result
     *
     * @return array{requests: int, ok: int, refused: int, other: int, firstError: string}
     */
    private function count(array $result, int $status, string $body): array
    {
        ++$result['requests'];

        if (200 === $status) {
            ++$result['ok'];

            return $result;
        }

        if (true === in_array($status, [403, 429], true)) {
            ++$result['refused'];
        } else {
            ++$result['other'];
        }

        if ('' === $result['firstError']) {
            $decoded = json_decode($body, true);
            $message = true === is_array($decoded) ? ($decoded['error']['message'] ?? null) : null;

            $result['firstError'] = sprintf('%d %s', $status, true === is_string($message) ? $message : '');
        }

        return $result;
    }
}
