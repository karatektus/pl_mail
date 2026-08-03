<?php

declare(strict_types=1);

namespace App\Tests\Domain\Filter;

use App\Domain\Filter\FilterAstValidator;
use App\Domain\Filter\InvalidFilterException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The validator guards a SQL compiler and stands between the browser and
 * stored rule state, so its job is to reject — never to sanitise. A silently
 * dropped condition widens the rule, and a filter that matches MORE mail than
 * the user asked for is the one outcome a rules engine must never produce.
 */
final class FilterAstValidatorTest extends TestCase
{
    private FilterAstValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new FilterAstValidator();
    }

    /**
     * @return iterable<string, array{array<string,mixed>}>
     */
    public static function validProvider(): iterable
    {
        // No conditions means every message, which is a rule somebody may want:
        // act on everything arriving in one account. It used to be rejected,
        // which made "label all of this account" impossible to express.
        yield 'no conditions'         => [[]];
        yield 'single text condition' => [['subject' => 'invoice']];
        yield 'implicit AND'          => [['subject' => 'invoice', 'hasAttachment' => true]];
        yield 'label id'              => [['hasLabel' => 7]];
        yield 'keyword'               => [['hasKeyword' => '$seen']];
        yield 'date'                  => [['after' => '2026-01-01T00:00:00Z']];
        // Available now that Postgres does the matching — search_vector gives
        // real stemming, which no PHP-side twin could have reproduced.
        yield 'full text'             => [['text' => 'invoice']];
        yield 'list id'               => [['listId' => 'acme.test']];
        yield 'operator tree'         => [[
            'operator' => 'AND',
            'conditions' => [
                ['from' => 'billing@'],
                ['operator' => 'NOT', 'conditions' => [['subject' => 'receipt']]],
            ],
        ]];
    }

    /**
     * @param array<string,mixed> $ast
     */
    #[DataProvider('validProvider')]
    public function testAcceptsSupportedTrees(array $ast): void
    {
        $this->validator->validate($ast);

        $this->expectNotToPerformAssertions();
    }

    /**
     * @return iterable<string, array{array<string,mixed>}>
     */
    public static function invalidProvider(): iterable
    {
        // Excluded from rules on purpose — see FilterVocabulary.
        yield 'inMailbox is JMAP-only'      => [['inMailbox' => 3]];
        yield 'no generic header condition' => [['header' => 'List-Id']];

        yield 'unknown condition'    => [['subjectt' => 'typo']];
        yield 'bad operator'         => [['operator' => 'XOR', 'conditions' => [['subject' => 'x']]]];
        yield 'empty group'          => [['operator' => 'AND', 'conditions' => []]];
        yield 'stray key on group'   => [['operator' => 'AND', 'conditions' => [['subject' => 'x']], 'subject' => 'y']];
        yield 'blank text'           => [['subject' => '   ']];
        yield 'text given a number'  => [['subject' => 42]];
        yield 'size given a string'  => [['minSize' => '100']];
        yield 'bool given a string'  => [['hasAttachment' => 'yes']];
        yield 'unknown keyword'      => [['hasKeyword' => '$important']];
        yield 'unparseable date'     => [['before' => 'the day before yesterday-ish']];
    }

    /**
     * @param array<string,mixed> $ast
     */
    #[DataProvider('invalidProvider')]
    public function testRejectsEverythingElse(array $ast): void
    {
        $this->expectException(InvalidFilterException::class);

        $this->validator->validate($ast);
    }

    /**
     * Unbounded nesting is a denial-of-service with no legitimate use — no
     * human builds an eleven-deep filter by hand.
     */
    public function testRejectsRunawayNesting(): void
    {
        $ast = ['subject' => 'deep'];

        for ($i = 0; $i < 12; $i++) {
            $ast = ['operator' => 'AND', 'conditions' => [$ast]];
        }

        $this->expectException(InvalidFilterException::class);

        $this->validator->validate($ast);
    }
}
