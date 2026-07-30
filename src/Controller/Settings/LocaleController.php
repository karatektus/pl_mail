<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\User\User;
use App\Domain\Enum\AppLocale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/settings/locale', name: 'app_settings_locale_')]
#[IsGranted('ROLE_USER')]
final class LocaleController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Changing the language re-renders every string on the page, so this
     * answers with a redirect rather than a Turbo stream: the follow-up GET
     * rebuilds the whole document in the new locale.
     */
    #[Route('', name: 'update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (false === $this->isCsrfTokenValid('settings-locale', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $locale = AppLocale::tryFromRequest((string) $request->request->get('locale'));

        if (null === $locale) {
            throw $this->createNotFoundException('Unknown locale.');
        }

        $user
            ->setLocale($locale->value)
            ->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        return $this->redirectToRoute('app_settings_index', ['section' => 'general']);
    }
}
