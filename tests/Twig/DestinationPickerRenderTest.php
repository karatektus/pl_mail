<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Domain\DTO\Integration\Entry;
use App\Domain\DTO\Integration\Listing;
use App\Domain\Enum\Integration\Provider;
use App\Entity\Integration\Integration;
use App\Entity\Mail\MessagePart;
use App\Entity\User\User;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;

/**
 * What the destination chooser draws for each kind of container.
 *
 * Paperless has no folders — a save files under a TAG, and the driver offers the
 * tags as a flat list with nothing beneath any of them. The chooser used to
 * decide "flat or tree" by asking whether the provider was a photo library, so
 * Paperless fell into the tree branch: every tag was drawn as a link that
 * navigates INTO it, the driver answered with the very same flat list, and the
 * dialog appeared to do nothing when a tag was clicked. Nothing in the markup
 * could save anywhere except the footer's "Save here", which files under no tag.
 *
 * Asserted on the rendered HTML rather than on the driver, because the driver
 * was right all along; the fault was in what the template did with a flat list.
 *
 * The page layout is replaced by an empty shell: it needs a whole request's
 * worth of appearance and session state that is nothing to do with this
 * template, and the chooser's own markup is all that is under test.
 */
final class DestinationPickerRenderTest extends KernelTestCase
{
    private Environment $twig;
    private TranslatorInterface $translator;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->twig       = $container->get(Environment::class);
        $this->translator = $container->get(TranslatorInterface::class);

        // The save controls carry CSRF tokens, and the token manager reads the
        // session off the request stack — empty outside a real request.
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get('request_stack')->push($request);

        $this->twig->setLoader(new ChainLoader([
            new ArrayLoader(['_layout/app.html.twig' => '{% block body %}{% endblock %}']),
            $this->twig->getLoader(),
        ]));

        $this->user = new User();
    }

    /**
     * The regression itself. A tag is a destination: clicking it must be the
     * choice, not a trip to a list that looks identical.
     */
    public function testAPaperlessTagIsAChoiceRatherThanALinkIntoItself(): void
    {
        $html = $this->render(Provider::Paperless, [
            Entry::folder('7', 'Taxes'),
            Entry::folder('9', 'Invoices'),
        ]);

        self::assertStringContainsString('data-destination-folder="7"', $html);
        self::assertStringContainsString('data-destination-label="Taxes"', $html);
        self::assertStringContainsString('data-destination-folder="9"', $html);

        // The failing shape: a link that browses to the tag and re-renders the
        // same flat list. Neither id may appear in a browse URL.
        self::assertStringNotContainsString('folder=7', $html);
        self::assertStringNotContainsString('folder=9', $html);
    }

    /**
     * Presence companion to the test above. "No link into a tag" would also
     * hold for an empty chooser, so this pins that the other half — filing
     * under no tag at all — is still on offer, and is the only thing at the
     * root that is.
     */
    public function testPaperlessOffersNoTagAsTheRootChoiceAndNoFooterToSaveHere(): void
    {
        $html = $this->render(Provider::Paperless, [Entry::folder('7', 'Taxes')]);

        self::assertStringContainsString('data-destination-folder=""', $html);
        self::assertStringContainsString($this->translator->trans('integration.destination.no_tag'), $html);

        // A flat list has no "here" to browse to; the footer's save-here would
        // be a second, unlabelled way to file under no tag.
        self::assertStringNotContainsString(
            $this->translator->trans('integration.destination.choose_here'),
            $html,
        );
    }

    /**
     * In the save flow — the one the reading pane starts — a tag row is a form
     * that posts the upload with the tag as its destination, the way an Immich
     * album does. Pick mode shares the row's markup and differs only in the
     * wrapper, so this is the half of it that actually moves a file.
     */
    public function testAPaperlessTagPostsASaveWhenThereIsAnAttachmentInHand(): void
    {
        $html = $this->render(Provider::Paperless, [Entry::folder('7', 'Taxes')], withPart: true);

        self::assertStringContainsString('name="destination" value="7"', $html);
        self::assertStringContainsString('/save-attachment/', $html);
        self::assertStringNotContainsString('folder=7', $html);
    }

    /** Called tags, not albums: Paperless has neither folders nor albums. */
    public function testPaperlessSpeaksOfTagsNotAlbums(): void
    {
        $html = $this->render(Provider::Paperless, [], withPart: true);

        self::assertStringContainsString(
            $this->translator->trans('integration.destination.no_tags'),
            $html,
        );
        self::assertStringContainsString(
            $this->translator->trans('integration.destination.choose_tag', ['%file%' => 'invoice.pdf']),
            $html,
        );
        self::assertStringNotContainsString(
            $this->translator->trans('integration.destination.no_albums'),
            $html,
        );
    }

    /**
     * The two kinds this change must not move. A file store still navigates
     * into a folder, and a photo library's album is still a choice — if the
     * flat/tree decision were inverted for everyone, the Paperless tests above
     * would pass for the wrong reason.
     */
    public function testAFileStoreStillNavigatesIntoAFolder(): void
    {
        $html = $this->render(Provider::Nextcloud, [Entry::folder('Mail/Invoices', 'Invoices')]);

        self::assertStringContainsString('browse?folder=Mail/Invoices&amp;mode=destination', $html);
        self::assertStringNotContainsString('data-destination-label="Invoices"', $html);
        self::assertStringContainsString(
            $this->translator->trans('integration.destination.choose_here'),
            $html,
        );
    }

    public function testAPhotoLibraryAlbumIsStillAChoice(): void
    {
        $html = $this->render(Provider::Immich, [Entry::folder('a1', 'Holiday')]);

        self::assertStringContainsString('data-destination-folder="a1"', $html);
        self::assertStringNotContainsString('folder=a1', $html);
        self::assertStringContainsString($this->translator->trans('integration.destination.library'), $html);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * @param list<Entry> $folders
     */
    private function render(Provider $provider, array $folders, bool $withPart = false): string
    {
        $integration = new Integration($this->user, $provider, 'Test connection');
        $this->setId($integration, 5);

        $part = null;

        if (true === $withPart) {
            $part           = new MessagePart();
            $part->filename = 'invoice.pdf';
            $this->setId($part, 42);
        }

        return $this->twig->render('integration/_destination.html.twig', [
            'integration' => $integration,
            'part'        => $part,
            'listing'     => new Listing($folders, [Entry::folder('', $provider->label())]),
            'error'       => null,
            'createError' => null,
            'folderId'    => null,
            'canCreate'   => false,
        ]);
    }

    private function setId(object $entity, int $id): void
    {
        (new ReflectionProperty($entity::class, 'id'))->setValue($entity, $id);
    }
}
