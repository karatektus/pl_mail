<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DTO\Mail\ForwardAgent;
use App\Domain\Helper\AddressHelper;
use App\Entity\User\User;

/**
 * The person's list of agents for "Forward to agent", kept in their settings
 * bag as an ordered list of name and address.
 *
 * In the bag rather than a table of its own: it is a short list one person
 * edits by hand, nothing joins against it, and a fork-only feature that needs
 * no migration stays out of the way of upstream's schema.
 *
 * Order is the order they were added, and the first is the one the dialog
 * opens on. An address is on the list once; adding it again renames it.
 */
final readonly class ForwardAgents
{
    public const string SETTING = 'compose.forward_agents';

    public const int MAX_AGENTS = 9;

    public const int MAX_NAME_CHARS = 60;

    /** @return list<ForwardAgent> */
    public function all(User $user): array
    {
        $stored = $user->getSetting(self::SETTING, []);

        if (false === is_array($stored)) {
            return [];
        }

        $agents = [];

        foreach ($stored as $row) {
            if (false === is_array($row) || false === AddressHelper::isValidEmail($row['address'] ?? null)) {
                continue;
            }

            $agents[] = new ForwardAgent(
                (string) ($row['name'] ?? ''),
                AddressHelper::email($row['address']),
            );
        }

        return $agents;
    }

    /** The agent on this person's list with this address, or null when there is none. */
    public function find(User $user, string $address): ?ForwardAgent
    {
        $address = AddressHelper::email($address);

        foreach ($this->all($user) as $agent) {
            if ($address === $agent->address) {
                return $agent;
            }
        }

        return null;
    }

    /**
     * Add an agent, or rename the one already at this address.
     *
     * Answers false and changes nothing when the address is not an address or
     * the list is full; the caller flushes.
     */
    public function add(User $user, string $name, string $address): bool
    {
        if (false === AddressHelper::isValidEmail($address)) {
            return false;
        }

        $address = AddressHelper::email($address);
        $name    = mb_substr(trim($name), 0, self::MAX_NAME_CHARS);
        $agent   = new ForwardAgent('' !== $name ? $name : $address, $address);
        $agents  = $this->all($user);

        foreach ($agents as $i => $existing) {
            if ($address === $existing->address) {
                $agents[$i] = $agent;
                $this->store($user, $agents);

                return true;
            }
        }

        if (count($agents) >= self::MAX_AGENTS) {
            return false;
        }

        $agents[] = $agent;
        $this->store($user, $agents);

        return true;
    }

    public function remove(User $user, string $address): void
    {
        $address = AddressHelper::email($address);

        $this->store($user, array_values(array_filter(
            $this->all($user),
            static fn (ForwardAgent $agent): bool => $address !== $agent->address,
        )));
    }

    /** @param list<ForwardAgent> $agents */
    private function store(User $user, array $agents): void
    {
        $user->setSetting(self::SETTING, [] === $agents ? null : array_map(
            static fn (ForwardAgent $agent): array => ['name' => $agent->name, 'address' => $agent->address],
            $agents,
        ));
    }
}
