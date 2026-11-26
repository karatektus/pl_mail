<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\DTO\Mail\ForwardAgent;
use App\Entity\User\User;
use App\Service\Mail\ForwardAgents;
use PHPUnit\Framework\TestCase;

/**
 * The list "Forward to agent" picks from, as it is kept in the settings bag.
 */
final class ForwardAgentsTest extends TestCase
{
    private ForwardAgents $agents;
    private User $user;

    protected function setUp(): void
    {
        $this->agents = new ForwardAgents();
        $this->user   = new User();
    }

    public function testAgentsKeepTheOrderTheyWereAddedIn(): void
    {
        self::assertTrue($this->agents->add($this->user, 'Research bot', 'research@example.test'));
        self::assertTrue($this->agents->add($this->user, 'Inbox helper', 'Helper@Example.test'));

        self::assertEquals(
            [
                new ForwardAgent('Research bot', 'research@example.test'),
                new ForwardAgent('Inbox helper', 'helper@example.test'),
            ],
            $this->agents->all($this->user),
        );
    }

    public function testAddingAnAddressAgainRenamesItInPlace(): void
    {
        $this->agents->add($this->user, 'First', 'one@example.test');
        $this->agents->add($this->user, 'Second', 'two@example.test');
        $this->agents->add($this->user, 'Renamed', 'ONE@example.test');

        self::assertEquals(
            [
                new ForwardAgent('Renamed', 'one@example.test'),
                new ForwardAgent('Second', 'two@example.test'),
            ],
            $this->agents->all($this->user),
        );
    }

    public function testAnAgentWithoutANameIsCalledByItsAddress(): void
    {
        $this->agents->add($this->user, '   ', 'bot@example.test');

        self::assertSame('bot@example.test', $this->agents->all($this->user)[0]->name);
    }

    public function testSomethingThatIsNotAnAddressIsRefused(): void
    {
        self::assertFalse($this->agents->add($this->user, 'Nobody', 'not an address'));
        self::assertSame([], $this->agents->all($this->user));
    }

    public function testAFullListRefusesANewAgentButStillRenames(): void
    {
        for ($i = 1; $i <= ForwardAgents::MAX_AGENTS; ++$i) {
            self::assertTrue($this->agents->add($this->user, "Agent {$i}", "agent{$i}@example.test"));
        }

        self::assertFalse($this->agents->add($this->user, 'One too many', 'extra@example.test'));
        self::assertTrue($this->agents->add($this->user, 'Still renames', 'agent1@example.test'));
        self::assertCount(ForwardAgents::MAX_AGENTS, $this->agents->all($this->user));
    }

    public function testFindOnlyAnswersForAnAddressOnTheList(): void
    {
        $this->agents->add($this->user, 'Research bot', 'research@example.test');

        self::assertEquals(
            new ForwardAgent('Research bot', 'research@example.test'),
            $this->agents->find($this->user, 'Research@Example.test'),
        );
        self::assertNull($this->agents->find($this->user, 'stranger@example.test'));
    }

    public function testRemovingTheLastAgentEmptiesTheList(): void
    {
        $this->agents->add($this->user, 'Research bot', 'research@example.test');
        $this->agents->remove($this->user, 'research@example.test');

        self::assertSame([], $this->agents->all($this->user));
        self::assertNull($this->user->getSetting(ForwardAgents::SETTING));
    }
}
