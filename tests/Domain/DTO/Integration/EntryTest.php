<?php

declare(strict_types=1);

namespace App\Tests\Domain\DTO\Integration;

use App\Domain\DTO\Integration\Entry;
use App\Domain\DTO\Integration\Listing;
use App\Domain\Enum\Integration\EntryKind;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The picker DTOs, and one invariant that exists because of a real outage.
 *
 * Entry carried a public $kind property alongside a kind() accessor. Twig
 * resolves a property before a method of the same name, so every template
 * reached the raw null instead of the accessor and the picker died on any
 * listing that was not empty. The e2e suite missed it because it has no real
 * Immich to talk to, so it only ever rendered the error branch.
 */
final class EntryTest extends TestCase
{
    public function testKindFallsBackToTheObviousOne(): void
    {
        self::assertSame(EntryKind::Folder, Entry::folder('f1', 'Docs')->kind());
        self::assertSame(EntryKind::File, new Entry('d1', 'a.pdf', false)->kind());
        self::assertSame(EntryKind::Person, Entry::person('p1', 'Ada')->kind());
    }

    public function testOnlyAPersonIsAPerson(): void
    {
        self::assertTrue(Entry::person('p1', 'Ada')->isPerson());
        self::assertFalse(Entry::folder('f1', 'Docs')->isPerson());
        self::assertFalse(new Entry('d1', 'a.pdf', false)->isPerson());
    }

    public function testListingSeparatesFoldersFilesAndPeople(): void
    {
        $listing = new Listing([
            Entry::folder('f1', 'Docs'),
            new Entry('d1', 'a.pdf', false),
            Entry::person('p1', 'Ada'),
        ]);

        // A person is navigable, so it counts as a folder too — the people
        // helper is what tells the picker to render portraits.
        self::assertSame(['Docs', 'Ada'], array_map(static fn ($e) => $e->name, $listing->folders()));
        self::assertSame(['a.pdf'], array_map(static fn ($e) => $e->name, $listing->files()));
        self::assertSame(['Ada'], array_map(static fn ($e) => $e->name, $listing->people()));
    }

    public function testPeopleIsEmptyForAnOrdinaryListing(): void
    {
        // The template branches on this, and it must be safe to ask of a listing
        // that has never heard of a person.
        self::assertSame([], new Listing([Entry::folder('f1', 'Docs')])->people());
        self::assertSame([], new Listing([])->people());
    }

    /**
     * No public property may share a name with a public method on a DTO a
     * template touches.
     *
     * This is the invariant behind the outage: Twig silently prefers the
     * property, so the collision is not a compile error anywhere — it is a
     * runtime failure in whichever template happened to call the accessor.
     */
    public function testNoPropertyShadowsAnAccessorOnThePickerDtos(): void
    {
        foreach ([Entry::class, Listing::class] as $class) {
            $reflection = new ReflectionClass($class);

            $properties = array_map(
                static fn (\ReflectionProperty $p): string => $p->getName(),
                $reflection->getProperties(\ReflectionProperty::IS_PUBLIC),
            );

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                self::assertNotContains(
                    $method->getName(),
                    $properties,
                    sprintf(
                        '%s::%s() is shadowed by a property of the same name; Twig will read the property.',
                        $reflection->getShortName(),
                        $method->getName(),
                    ),
                );
            }
        }
    }
}
