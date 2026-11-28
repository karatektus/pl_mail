<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Entity\User\User;
use App\Repository\Mail\TrustedImageSender\TrustedImageSenderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Settings → Reading & writing → Reading: whether remote images in mail wait to be asked
 * for (#124), and whose images never do (#807).
 *
 * The switch is off until somebody turns it on. MessageRenderer is what reads
 * it; this controller only records the choice. Switching it off again puts
 * back exactly the senders somebody had trusted before, because it does not
 * touch the allowlist.
 *
 * The allowlist is built mostly from the "Always show images from …" buttons
 * on a message (Mail\RemoteImageController). Here it can be read in full,
 * pruned, and added to by hand: several entries at once, addresses or
 * domains, which is the only way to trust a domain the images bar will not
 * offer (gmail.com and the like, see MessageRenderer::trustableDomain()).
 *
 * A token on every form, and here for the reason the allowlist's own buttons
 * carry one: a cross-site POST that turned the switch on, or added a sender,
 * would make later mail a tracking pixel the reader never agreed to.
 */
#[IsGranted('ROLE_USER')]
final class ImageSettingsController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly TrustedImageSenderRepository $senders,
        private readonly TranslatorInterface          $translator,
    ) {}

    #[Route('/settings/images', name: 'app_settings_images_update', methods: ['POST'])]
    public function update(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->user();

        $this->assertCsrf($request, 'settings-images');

        // Read only when posted, like every control on the settings pages: a
        // form that did not mention the switch must not move it.
        if (true === $request->request->has('alwaysShow')) {
            $user->alwaysShowImages = '1' === (string) $request->request->get('alwaysShow');
        }

        $em->flush();

        return $this->redirectToRoute('app_settings_page', ['page' => 'reading']);
    }

    /**
     * One field, any number of entries: separated by commas, semicolons, spaces
     * or new lines, because a list pasted out of anywhere uses one of those.
     * What is not an address or a domain is named back rather than dropped in
     * silence, and the rest are added all the same.
     */
    #[Route('/settings/images/senders', name: 'app_settings_images_senders_add', methods: ['POST'])]
    public function addSenders(Request $request): Response
    {
        $user = $this->user();

        $this->assertCsrf($request, 'settings-images-senders');

        $parts    = preg_split('/[\s,;]+/', (string) $request->request->get('entries', '')) ?: [];
        $rejected = [];

        foreach (array_filter($parts, static fn (string $part): bool => '' !== $part) as $part) {
            if (false === $this->senders->add($user, $part)) {
                $rejected[] = $part;
            }
        }

        if ([] !== $rejected) {
            $this->addFlash('error', $this->translator->trans('settings.images.senders.rejected', [
                '%count%'   => count($rejected),
                '%entries%' => implode(', ', $rejected),
            ]));
        }

        return $this->backToCard();
    }

    #[Route('/settings/images/senders/{id}/remove', name: 'app_settings_images_senders_remove', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function removeSender(Request $request, int $id): Response
    {
        $user = $this->user();

        $this->assertCsrf($request, 'settings-images-sender-remove' . $id);

        $this->senders->removeEntry($user, $id);

        return $this->backToCard();
    }

    #[Route('/settings/images/senders/clear', name: 'app_settings_images_senders_clear', methods: ['POST'])]
    public function clearSenders(Request $request): Response
    {
        $user = $this->user();

        $this->assertCsrf($request, 'settings-images-senders-clear');

        $this->senders->deleteForUser($user);

        return $this->backToCard();
    }

    private function user(): User
    {
        $user = $this->getUser();

        if (false === $user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /** Back to the list, scrolled to it: it sits below the switch, often past the fold. */
    private function backToCard(): Response
    {
        return $this->redirectToRoute('app_settings_page', ['page' => 'reading', '_fragment' => 'card-images']);
    }
}
