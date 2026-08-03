<?php

declare(strict_types=1);

namespace App\Domain\Filter;

/**
 * Validates a rule's condition tree before it is stored.
 *
 * The stored AST is fed to EmailFilterCompiler, which builds SQL from it. The
 * compiler binds every *value* as a parameter, so this is not the last line of
 * defence against injection — but the shape still has to be checked here,
 * because a malformed tree that reaches the compiler surfaces as a JMAP
 * MethodException from a web controller, and an unbounded nesting depth is a
 * denial-of-service with no upside.
 *
 * Rejects rather than sanitises: silently dropping an unrecognised condition
 * would widen the rule, and a filter that matches more mail than the user
 * asked for is exactly what a rules engine must never do.
 */
final class FilterAstValidator
{
    /**
     * Deep enough for any rule a person builds by hand, shallow enough that
     * the recursive walk cannot blow the stack.
     */
    private const int MAX_DEPTH = 10;

    private const int MAX_CONDITIONS = 100;

    private int $conditionCount = 0;

    /**
     * @param array<string,mixed> $ast
     *
     * @throws InvalidFilterException
     */
    public function validate(array $ast): void
    {
        $this->conditionCount = 0;

        // An empty tree means "every message", which is a rule somebody may
        // legitimately want: act on everything arriving in one account. It is
        // allowed only here, at the top — an operator group with no conditions
        // is a tree the client built wrong, and operator() still rejects it.
        if (0 === count($ast)) {
            return;
        }

        $this->node($ast, 0);
    }

    /**
     * @param array<string,mixed> $node
     */
    private function node(array $node, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidFilterException(sprintf('Filter nesting is deeper than %d levels.', self::MAX_DEPTH));
        }

        if (true === array_key_exists('operator', $node)) {
            $this->operator($node, $depth);

            return;
        }

        $this->condition($node);
    }

    /**
     * @param array<string,mixed> $node
     */
    private function operator(array $node, int $depth): void
    {
        $operator = $node['operator'];

        if (false === is_string($operator) || false === in_array($operator, FilterVocabulary::OPERATORS, true)) {
            throw new InvalidFilterException('Operator must be AND, OR or NOT.');
        }

        $conditions = $node['conditions'] ?? null;

        if (false === is_array($conditions) || 0 === count($conditions)) {
            throw new InvalidFilterException(sprintf('The %s group needs at least one condition.', $operator));
        }

        // An operator node carries nothing else; a stray key here means the
        // client built the tree wrong and the compiler would ignore it.
        foreach (array_keys($node) as $key) {
            if ('operator' !== $key && 'conditions' !== $key) {
                throw new InvalidFilterException(sprintf('Unexpected "%s" alongside an operator.', (string) $key));
            }
        }

        foreach ($conditions as $child) {
            if (false === is_array($child)) {
                throw new InvalidFilterException('Each entry in a group must be an object.');
            }

            $this->node($child, $depth + 1);
        }
    }

    /**
     * @param array<string,mixed> $node
     */
    private function condition(array $node): void
    {
        if (0 === count($node)) {
            // The compiler renders this as TRUE — a rule matching every message
            // is never what someone meant to build.
            throw new InvalidFilterException('A condition cannot be empty.');
        }

        foreach ($node as $property => $value) {
            $property = (string) $property;

            $this->conditionCount++;

            if ($this->conditionCount > self::MAX_CONDITIONS) {
                throw new InvalidFilterException(sprintf('A rule cannot have more than %d conditions.', self::MAX_CONDITIONS));
            }

            if (false === FilterVocabulary::supports($property)) {
                throw new InvalidFilterException(sprintf('Condition "%s" is not supported in rules.', $property));
            }

            $this->value($property, $value);
        }
    }

    private function value(string $property, mixed $value): void
    {
        if (true === in_array($property, FilterVocabulary::TEXT_CONDITIONS, true)) {
            if (false === is_string($value) || '' === trim($value)) {
                throw new InvalidFilterException(sprintf('"%s" needs some text to match.', $property));
            }

            return;
        }

        if (true === in_array($property, FilterVocabulary::INT_CONDITIONS, true)) {
            if (false === is_int($value)) {
                throw new InvalidFilterException(sprintf('"%s" must be a whole number.', $property));
            }

            return;
        }

        if (true === in_array($property, FilterVocabulary::DATE_CONDITIONS, true)) {
            if (false === is_string($value)) {
                throw new InvalidFilterException(sprintf('"%s" must be a date.', $property));
            }

            try {
                new \DateTimeImmutable($value);
            } catch (\Exception) {
                throw new InvalidFilterException(sprintf('"%s" is not a valid date.', $value));
            }

            return;
        }

        if (true === in_array($property, FilterVocabulary::BOOL_CONDITIONS, true)) {
            if (false === is_bool($value)) {
                throw new InvalidFilterException(sprintf('"%s" must be true or false.', $property));
            }

            return;
        }

        // Keyword conditions.
        if (false === is_string($value) || false === in_array($value, FilterVocabulary::KEYWORDS, true)) {
            throw new InvalidFilterException(sprintf('"%s" must be one of: %s.', $property, implode(', ', FilterVocabulary::KEYWORDS)));
        }
    }
}
