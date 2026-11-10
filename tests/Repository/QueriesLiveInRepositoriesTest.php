<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every query lives in a repository (CODESTYLE.md §5.3), and this keeps it so.
 *
 * Written down from the start of the project and still broken in 55 files by
 * the time anybody counted: services, controllers, a Twig extension, message
 * handlers and listeners each with a createQuery(), an executeStatement() or an
 * EntityManager::find() of their own. A rule that only lives in a document is
 * a rule each new file gets to rediscover, so this reads every PHP file under
 * src/ outside src/Repository/ and fails on the calls that are queries.
 *
 * Read through PHP's own tokenizer with comments and strings dropped, so a
 * docblock that names createQueryBuilder() is not a query. Calls ON a
 * repository ($this->messages->findBy(…)) are not matched and are the point.
 */
final class QueriesLiveInRepositoriesTest extends TestCase
{
    private const string SRC = __DIR__ . '/../../src';

    /**
     * What a query looks like from outside a repository: the ORM's query
     * builders, DQL and native queries, DBAL's statements and fetches, its
     * insert/update/delete helpers, and EntityManager::find() and
     * getRepository(), which are a repository's job reached around it.
     */
    private const string QUERY_CALL = '/->\s*(?:createQueryBuilder|createQuery|createNativeQuery|executeQuery|executeStatement|executeUpdate'
        . '|fetchAllAssociative|fetchAllAssociativeIndexed|fetchAssociative|fetchOne|fetchFirstColumn|fetchAllKeyValue|fetchAllNumeric|fetchNumeric'
        . '|iterateAssociative|getRepository)\s*\('
        . '|(?:(?:\$|->)(?:em|entityManager|manager|objectManager)|getObjectManager\(\)|getEntityManager\(\))\s*->\s*find\s*\('
        . '|(?:(?:\$|->)connection|getConnection\(\))\s*->\s*(?:insert|update|delete)\s*\(/';

    public function testNoQueryIsMadeOutsideARepository(): void
    {
        $found = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::SRC)) as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }

            $path = substr((string) $file->getRealPath(), strlen((string) realpath(self::SRC)) + 1);

            if (str_starts_with($path, 'Repository/')) {
                continue;
            }

            foreach (self::codeLines((string) file_get_contents($file->getPathname())) as $line => $code) {
                if (1 === preg_match(self::QUERY_CALL, $code)) {
                    $found[] = sprintf('src/%s:%d  %s', $path, $line, trim($code));
                }
            }
        }

        self::assertSame([], $found, "A query outside src/Repository — move it into the repository of the model it reads (CODESTYLE.md §5.3):\n" . implode("\n", $found));
    }

    /** The guard has to be able to fail, or it guards nothing. */
    public function testTheGuardSeesAQueryAndNotAComment(): void
    {
        $code = self::codeLines(<<<'PHP'
            <?php
            // $this->em->createQueryBuilder() in a comment
            $rows = $this->connection->fetchAllAssociative('SELECT 1');
            $user = $this->em->find(User::class, 1);
            $this->connection->insert('log_entry', []);
            $name = '->createQuery( in a string';
            PHP);

        $hits = array_keys(array_filter($code, static fn (string $line): bool => 1 === preg_match(self::QUERY_CALL, $line)));

        self::assertSame([3, 4, 5], $hits);
    }

    /**
     * The file's code by line number, without comments and with every string
     * literal emptied.
     *
     * @return array<int, string>
     */
    private static function codeLines(string $source): array
    {
        $lines = [];

        foreach (token_get_all($source) as $token) {
            if (false === is_array($token)) {
                $lines[array_key_last($lines) ?? 1] = ($lines[array_key_last($lines) ?? 1] ?? '') . $token;

                continue;
            }

            [$id, $text, $line] = $token;

            if (T_COMMENT === $id || T_DOC_COMMENT === $id) {
                continue;
            }

            if (T_CONSTANT_ENCAPSED_STRING === $id || T_ENCAPSED_AND_WHITESPACE === $id) {
                $text = "''";
            }

            // A token spanning lines is booked against its first one; enough to
            // name where the call starts.
            $lines[$line] = ($lines[$line] ?? '') . $text;
        }

        return $lines;
    }
}
