<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Domain\DTO\Integration\Listing;
use App\Domain\Enum\Integration\Capability;
use App\Domain\Enum\Integration\Provider;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/**
 * A first page with no entries is only "empty" when nothing follows it.
 *
 * The Immich driver leaves the hidden half of each Live Photo out of a page and
 * still hands on the cursor Immich sent with it, so a page can come back with
 * no entries and more behind it. The picker answered every entry-less listing
 * with its "nothing here" line and no results container — and the script that
 * loads the next page appends into that container. With none to append into it
 * fetched page after page and dropped each one, under a line saying the library
 * was empty.
 *
 * Rendered rather than driven through a browser: the claim is about which
 * markup the first response carries, and no test stack has an Immich to page.
 */
final class PickerEmptyFirstPageRenderTest extends KernelTestCase
{
    private const string RESULTS  = 'data-integration--integration-picker-target="results"';
    private const string SENTINEL = 'data-integration--integration-picker-target="sentinel"';

    private EntityManagerInterface $em;
    private Connection $connection;
    private Environment $twig;
    private Integration $integration;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->twig       = $container->get(Environment::class);

        // The picker carries a CSRF token, and the token manager reads the
        // session off the request stack — empty outside a real request.
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get('request_stack')->push($request);

        $this->connection->beginTransaction();
        $this->integration = $this->seedIntegration();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnEmptyFirstPageWithMoreBehindItKeepsSomewhereToAppendRatherThanSayingEmpty(): void
    {
        $html = $this->render(new Listing([], nextCursor: '2'));

        self::assertStringContainsString(self::RESULTS, $html, 'the next page needs a container to land in');
        self::assertStringContainsString(self::SENTINEL, $html, 'and the link that fetches it');
        self::assertStringNotContainsString($this->emptyLine(), $html);
    }

    /** The contrast: with no cursor there is nothing to wait for, and it says so. */
    public function testAnEmptyListingWithNothingBehindItStillSaysEmpty(): void
    {
        $html = $this->render(new Listing([]));

        self::assertStringContainsString($this->emptyLine(), $html);
        self::assertStringNotContainsString(self::RESULTS, $html);
        self::assertStringNotContainsString(self::SENTINEL, $html);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * The variables FilePickerController::browse() hands the template, for an
     * Immich connection with nothing typed into the search.
     */
    private function render(Listing $listing): string
    {
        return $this->twig->render('integration/_picker.html.twig', [
            'integration' => $this->integration,
            'listing'     => $listing,
            'error'       => null,
            'folderId'    => null,
            'draftId'     => 0,
            'query'       => null,
            'maxBytes'    => 1_000,
            'canLink'     => $this->integration->supports(Capability::ShareLink),
            'canThumb'    => $this->integration->supports(Capability::Thumbnail),
            'canSearch'   => false,
            'buckets'     => [],
        ]);
    }

    private function emptyLine(): string
    {
        return self::getContainer()->get('translator')->trans('integration.picker.empty');
    }

    /** Persisted, because the picker's links are built from the integration's id. */
    private function seedIntegration(): Integration
    {
        $user            = new User();
        $user->email     = sprintf('picker-page-%s@example.test', uniqid('', true));
        $user->nameFirst = 'Picker';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $integration = new Integration($user, Provider::Immich, 'Immich');
        $this->em->persist($integration);

        $this->em->flush();

        return $integration;
    }
}
