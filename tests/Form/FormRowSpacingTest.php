<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Repository\User\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The form theme, not the page, owns the gap between fields.
 *
 * Symfony renders every row of a compound form into one bare wrapper <div>.
 * That div is a single child of whatever holds the form, so a `space-y-*` on
 * the modal body sets the gap *around* the field block and never *between* the
 * fields — raising it looks like it should work and does nothing, which is how
 * this went unnoticed through two attempts to fix it by hand.
 *
 * `form_widget_compound` in the modal theme puts the rhythm on the wrapper
 * instead. This pins that down: the element that actually parents the rows has
 * to carry vertical spacing.
 */
final class FormRowSpacingTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    public function testRootFormWrapperSpacesItsRows(): void
    {
        $client = static::createClient();

        $user = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);
        $crawler = $client->request('GET', '/labels/new');

        self::assertSame(200, $client->getResponse()->getStatusCode());

        // form_row's own wrapper, straight from the theme.
        $rows = $crawler->filter('form div.flex.flex-col.gap-2');
        self::assertGreaterThan(1, $rows->count(), 'need a multi-field form to measure');

        // Assert on the rows' immediate parent, not on any ancestor: the modal
        // body above it already has a space-y-* of its own, and matching that
        // one is exactly the mistake this test exists to catch.
        $parentClass = $rows->first()->ancestors()->first()->attr('class') ?? '';

        self::assertStringContainsString(
            'space-y-',
            $parentClass,
            'the wrapper that parents the form rows carries no vertical rhythm, '
            . 'so the fields render flush however high the modal body spacing goes',
        );
    }
}
