<?php

declare(strict_types=1);

namespace App\Command\Diagnostics;

use App\Entity\Mail\Account;
use App\Repository\Mail\AccountRepository;
use App\Service\OAuth\OAuthTokenManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Probes a Microsoft account's Graph access endpoint by endpoint, in order of
 * increasing privilege, so a 403 can be attributed rather than guessed at.
 *
 * Deliberately does NOT go through GraphApiClient — the point is to see raw
 * status codes and Graph error codes without the client's exception mapping
 * swallowing the detail.
 *
 * On token decoding: work/school accounts issue JWT access tokens whose `scp`
 * claim can be inspected directly. Personal Microsoft accounts do not — they
 * issue opaque compact tokens (recognisable by the `Ew…` prefix, paired with an
 * `M.C…_BAY…` refresh token). There is nothing to decode there, which is
 * exactly why this command probes live endpoints instead.
 */
#[AsCommand(
    name: 'app:graph:diagnose',
    description: 'Probe Microsoft Graph access for one account and report what works',
)]
final class GraphDiagnoseCommand extends Command
{
    private const string BASE = 'https://graph.microsoft.com/v1.0';

    public function __construct(
        private readonly AccountRepository   $accountRepository,
        private readonly OAuthTokenManager   $tokenManager,
        private readonly HttpClientInterface $httpClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('accountId', InputArgument::REQUIRED, 'Local Account id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $account = $this->accountRepository->find((int) $input->getArgument('accountId'));

        if (null === $account) {
            $io->error('No such account.');

            return Command::FAILURE;
        }

        if (false === $account->isMicrosoft()) {
            $io->error(sprintf('Account %d is not a Microsoft account.', $account->id));

            return Command::FAILURE;
        }

        $io->title(sprintf('Graph diagnosis — #%d %s', $account->id, $account->email));

        $this->reportToken($io, $account);

        try {
            $token = $this->tokenManager->getValidAccessToken($account);
        } catch (\Throwable $e) {
            $io->error('Could not obtain an access token: ' . $e->getMessage());

            return Command::FAILURE;
        }
        // ── Identity payload dump ─────────────────────────────────────────────
        // Personal accounts often report the primary alias in mail/UPN while the
        // address mail is actually delivered to lives only as a secondary
        // `smtp:` proxy entry. Print everything so we can see where it is.
        $identity = $this->httpClient->request('GET', self::BASE . '/me', [
            'auth_bearer' => $token,
            'query'       => [
                '$select' => 'email,mail,userPrincipalName,proxyAddresses,otherMails,displayName, mailboxSettings',
            ],
        ])->toArray(false);

        $io->section('Identity payload');
        $io->definitionList(
            ['email'              => $identity['email']              ?? '(null)'],
            ['mail'              => $identity['mail']              ?? '(null)'],
            ['userPrincipalName' => $identity['userPrincipalName'] ?? '(null)'],
            ['displayName'       => $identity['displayName']       ?? '(null)'],
        );

        $proxies = $identity['proxyAddresses'] ?? [];
        $others  = $identity['otherMails']     ?? [];

        $io->writeln('proxyAddresses:');

        if (count($proxies) === 0) {
            $io->writeln('  (empty)');
        }

        foreach ($proxies as $proxy) {
            $io->writeln('  ' . $proxy);
        }

        $io->writeln('otherMails:');

        if (count($others) === 0) {
            $io->writeln('  (empty)');
        }

        foreach ($others as $other) {
            $io->writeln('  ' . $other);
        }

        // ── Profile emails (beta) ─────────────────────────────────────────────
        // The only Graph surface that exposes secondary aliases for a personal
        // account: /me and proxyAddresses hide them, /beta/me/profile/emails
        // lists the full set. Beta, so best-effort only.
        $profileEmails = $this->httpClient->request(
            'GET',
            'https://graph.microsoft.com/beta/me/profile/emails',
            ['auth_bearer' => $token],
        )->toArray(false);

        $io->section('Profile emails (beta)');

        $entries = $profileEmails['value'] ?? [];

        if (count($entries) === 0) {
            $io->writeln('  (none returned — or the beta endpoint refused this account)');
        }

        foreach ($entries as $entry) {
            $io->writeln(sprintf(
                '  %s  [type: %s]  %s',
                $entry['address']     ?? '(no address)',
                $entry['type']        ?? '(no type)',
                $entry['displayName'] ?? '',
            ));
        }
        // Ordered by increasing privilege: identity, then mailbox existence,
        // then read, then the optional extras. The first failure localises the
        // problem.
        // 'required' marks probes that must pass for sync to work at all.
        // Master categories are optional: without MailboxSettings.ReadWrite the
        // category axis degrades, but folders, messages and send are unaffected.
        $probes = [
            ['identity',   'User.Read — identity',       self::BASE . '/me',                             [], true],
            ['inbox',      'Mail — inbox folder',        self::BASE . '/me/mailFolders/inbox',           [], true],
            ['folders',    'Mail — folder list (delta)', self::BASE . '/me/mailFolders/delta',           [], true],
            ['messages',   'Mail — first message',       self::BASE . '/me/messages?$top=1&$select=id',  ['Prefer' => 'IdType="ImmutableId"'], true],
            ['categories', 'Categories — master list',   self::BASE . '/me/outlook/masterCategories',    [], false],
            ['Me', 'Infos about met',   self::BASE . '/me?$select=mail,userPrincipalName,proxyAddresses,otherMails,displayName',    [], false],
        ];

        $rows    = [];
        $results = [];

        foreach ($probes as [$key, $label, $url, $headers, $required]) {
            [$status, $detail, $applied] = $this->probe('GET', $url, $token, $headers);

            $ok = $status >= 200 && $status < 300;

            $results[$key] = ['ok' => $ok, 'status' => $status, 'required' => $required];

            $rows[] = [
                $label . (true === $required ? '' : ' (optional)'),
                $status,
                $applied,
                $detail,
            ];
        }

        $io->table(['Probe', 'Status', 'Preference-Applied', 'Detail'], $rows);

        $this->interpret($io, $results);

        return Command::SUCCESS;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function reportToken(SymfonyStyle $io, Account $account): void
    {
        $access = (string) $account->oauthAccessToken;

        $kind = match (true) {
            str_starts_with($access, 'eyJ') => 'JWT (work/school account)',
            str_starts_with($access, 'Ew')  => 'opaque compact (personal Microsoft account)',
            default                         => 'unrecognised',
        };

        $io->definitionList(
            ['Token type'  => $kind],
            ['Expires'     => $account->oauthTokenExpiry?->format('Y-m-d H:i:s') ?? '(unset)'],
            ['Immutable ids' => match ($account->graphImmutableIds) {
                true    => 'supported',
                false   => 'NOT supported (dedup falls back to RFC Message-ID)',
                default => 'not yet probed',
            }],
        );

        if ('opaque compact (personal Microsoft account)' === $kind) {
            $io->note(
                'Personal accounts issue opaque tokens with no inspectable scope claim, '
                . 'so granted permissions can only be inferred from the probes below.'
            );
        }
    }

    /**
     * @param array<string,string> $headers
     * @return array{int, string, string}  status, detail, Preference-Applied
     */
    private function probe(string $method, string $url, string $token, array $headers): array
    {
        try {
            $response = $this->httpClient->request($method, $url, [
                'auth_bearer' => $token,
                'headers'     => $headers,
            ]);

            $status  = $response->getStatusCode();
            $applied = $response->getHeaders(false)['preference-applied'][0] ?? '';

            if ($status >= 200 && $status < 300) {
                return [$status, 'ok', (string) $applied];
            }

            $body    = json_decode($response->getContent(false), true);
            $code    = $body['error']['code'] ?? 'unknown';
            $message = $body['error']['message'] ?? '';

            return [$status, sprintf('%s — %s', $code, mb_substr((string) $message, 0, 90)), (string) $applied];
        } catch (\Throwable $e) {
            return [0, 'transport: ' . mb_substr($e->getMessage(), 0, 90), ''];
        }
    }

    /**
     * @param array<string, array{ok: bool, status: int, required: bool}> $results
     */
    private function interpret(SymfonyStyle $io, array $results): void
    {
        $identityOk = true === ($results['identity']['ok'] ?? false);

        $mailboxOk = true;

        foreach ($results as $result) {
            if (true === $result['required'] && false === $result['ok']) {
                $mailboxOk = false;
            }
        }

        if (true === $identityOk && false === $mailboxOk) {
            $io->warning(
                "Identity works but every mail endpoint fails.\n\n"
                . "That combination means the token is valid and Graph accepts it — the problem is the\n"
                . "mailbox, not the credentials. Two causes:\n\n"
                . "  1. No Outlook mailbox exists behind this Microsoft account. An MSA created from an\n"
                . "     external address (a custom domain hosted elsewhere) has no mailbox provisioned\n"
                . "     unless one was explicitly added. Confirm at outlook.live.com — if you land in a\n"
                . "     setup prompt rather than an inbox, this is it, and no configuration will fix it.\n\n"
                . "  2. The token predates the Mail.* permissions. Adding scopes in the app registration\n"
                . "     does NOT upgrade already-issued tokens. Delete the account in plMail and\n"
                . "     reconnect so a fresh consent issues a token carrying them."
            );

            return;
        }

        if (false === $identityOk) {
            $io->error(
                'Even /me fails, so the token itself is not being accepted. Check that '
                . 'OAuthProviderFactory points at https://graph.microsoft.com/ and that the account '
                . 'was connected after the Graph scopes were configured.'
            );

            return;
        }

        if (false === $mailboxOk) {
            return;
        }

        // Core sync works. Report optional gaps separately so they are not
        // mistaken for a broken account.
        if (false === ($results['categories']['ok'] ?? false)) {
            $io->warning(
                "Mail sync is healthy, but master categories are not accessible.\n\n"
                . "/me/outlook/masterCategories is NOT covered by Mail.ReadWrite — it lives under the\n"
                . "Outlook user-settings resource and needs MailboxSettings.ReadWrite.\n\n"
                . "Effect while missing: folders, messages, attachments and send all work. Categories\n"
                . "do not sync in either direction, so labels that are not folder-backed will not\n"
                . "appear in Outlook and Outlook categories will not appear in plMail.\n\n"
                . "Fix: add MailboxSettings.ReadWrite (delegated) in the app registration, then\n"
                . "delete and reconnect the account — adding a scope does not upgrade issued tokens."
            );

            return;
        }

        $io->success('All probes passed — Graph access is healthy for this account.');
    }
}
