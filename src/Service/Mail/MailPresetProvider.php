<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DTO\MailPreset;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the shipped provider presets. Deliberately file-backed rather than
 * persisted: presets are release artefacts, so they upgrade with the code and
 * need no migration or seeding on existing installations.
 */
final class MailPresetProvider
{
    /** @var array<string, MailPreset>|null */
    private ?array $presets = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/mail_presets.yaml')]
        private readonly string $presetFile,
    ) {
    }

    /**
     * @return array<string, MailPreset>
     */
    public function all(): array
    {
        if (null !== $this->presets) {
            return $this->presets;
        }

        if (false === is_file($this->presetFile)) {
            $this->presets = [];

            return $this->presets;
        }

        $parsed = Yaml::parseFile($this->presetFile);
        $rows   = $parsed['presets'] ?? [];
        $result = [];

        foreach ($rows as $key => $data) {
            $result[(string) $key] = MailPreset::fromArray((string) $key, $data);
        }

        uasort($result, static fn (MailPreset $a, MailPreset $b): int => strcasecmp($a->name, $b->name));

        $this->presets = $result;

        return $this->presets;
    }

    public function get(string $key): ?MailPreset
    {
        return $this->all()[$key] ?? null;
    }

    public function findByEmail(string $email): ?MailPreset
    {
        $at = strrpos($email, '@');

        if (false === $at) {
            return null;
        }

        return $this->findByDomain(substr($email, $at + 1));
    }

    public function findByDomain(string $domain): ?MailPreset
    {
        foreach ($this->all() as $preset) {
            if (true === $preset->matchesDomain($domain)) {
                return $preset;
            }
        }

        return null;
    }

    /**
     * Grouped choices for ChoiceType — Tom Select renders these as optgroups.
     *
     * @return array<string, array<string, string>>
     */
    public function choices(): array
    {
        $choices = [];

        foreach ($this->all() as $preset) {
            $choices[$preset->group][$preset->name] = $preset->key;
        }

        ksort($choices);

        return $choices;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function toClientArray(): array
    {
        $payload = [];

        foreach ($this->all() as $preset) {
            $payload[$preset->key] = $preset->toClientArray();
        }

        return $payload;
    }
}
