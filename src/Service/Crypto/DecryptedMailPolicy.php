<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Domain\Enum\Crypto\PlaintextSource;
use App\Entity\Ai\AiFeature;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Infrastructure\Http\PrivateNetwork;
use App\Repository\Ai\AiSettingsRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Who may keep the decrypted text of end-to-end encrypted mail on the server,
 * and which models may read it.
 *
 * Three layers, each able only to say no:
 *
 *   1. the installation — APP_E2E_SERVER_PLAINTEXT=forbid turns the whole
 *      feature off, for organisations that must not hold such text;
 *   2. the account — Account::$keepDecrypted, off by default;
 *   3. the models — Account::$keepDecryptedAi, off by default, and for a model
 *      host outside the installation's own network only with a consent
 *      recorded for that very host.
 *
 * "Outside the network" is decided by where the host resolves: loopback or a
 * private range is the installation's own (an Ollama beside it, a GPU box on
 * the LAN); anything else is a third party, whatever protocol it speaks.
 * AiSettings knows only the protocol, and an OpenAI-compatible server on the
 * LAN is as local as Ollama.
 */
final readonly class DecryptedMailPolicy
{
    public function __construct(
        private AiSettingsRepository $aiSettings,
        #[Autowire('%app.e2e_server_plaintext%')]
        private string               $installPolicy,
    ) {
    }

    public function allowedOnInstall(): bool
    {
        return 'forbid' !== strtolower(trim($this->installPolicy));
    }

    /** Whether this account's encrypted mail is decrypted onto the server. */
    public function keeps(Account $account): bool
    {
        return true === $this->allowedOnInstall() && true === $account->keepDecrypted;
    }

    /**
     * Whether a model feature may read this message. Every message the server
     * could always read may; one whose text a browser supplied only as far as
     * its account allows.
     */
    public function allowsAi(Message $message, AiFeature $feature): bool
    {
        if (PlaintextSource::Client !== $message->plaintextSource) {
            return true;
        }

        $account = $message->account;

        if (false === $this->keeps($account) || false === $account->keepDecryptedAi) {
            return false;
        }

        $host = $this->hostFor($feature);

        return null !== $host && (true === $this->isLocal($host) || $host === $account->getSetting(Account::SETTING_KEEP_DECRYPTED_AI_HOST));
    }

    /**
     * The model host the features would send decrypted mail to, and whether
     * it is the installation's own — what the settings card has to say before
     * anybody switches the models on.
     *
     * @return array{host: ?string, local: bool}
     */
    public function modelHost(): array
    {
        $host = $this->hostFor(AiFeature::Categorise);

        return ['host' => $host, 'local' => null !== $host && $this->isLocal($host)];
    }

    private function hostFor(AiFeature $feature): ?string
    {
        $host = parse_url($this->aiSettings->currentOrDefault()->connectionFor($feature)->baseUrl, PHP_URL_HOST);

        return is_string($host) && '' !== $host ? strtolower($host) : null;
    }

    private function isLocal(string $host): bool
    {
        $addresses = PrivateNetwork::resolve($host);

        // Every address it resolves to, not any: a name with one public record
        // among private ones is a name that can be pointed outside.
        return 'localhost' === $host
            || ([] !== $addresses && [] === array_filter($addresses, static fn (string $ip): bool => false === PrivateNetwork::isPrivateHost($ip)));
    }
}
