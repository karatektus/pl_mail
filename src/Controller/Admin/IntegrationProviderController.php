<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\Enum\Integration\AuthKind;
use App\Domain\Enum\Integration\Provider;
use App\Entity\IntegrationProviderConfig;
use App\Repository\IntegrationProviderConfigRepository;
use App\Repository\IntegrationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Which integration services this installation offers, and how to set them up.
 *
 * Every Provider case is listed, implemented or not. That is the point: the
 * unfinished ones show their setup tutorial and a "not available yet" chip
 * rather than being invisible, so an admin can see the whole roadmap and a
 * finished provider needs no change here to appear.
 *
 * Credentials are written but never read back — the form shows whether a
 * secret is on file, not what it is. Submitting the form with the secret field
 * left blank keeps the stored one, which is what lets an admin change the base
 * URL without re-pasting a client secret they no longer have.
 */
#[Route('/admin/integrations', name: 'app_admin_integrations_')]
#[IsGranted('ROLE_ADMIN')]
final class IntegrationProviderController extends AbstractController
{
    public function __construct(
        private readonly IntegrationProviderConfigRepository $configRepository,
        private readonly IntegrationRepository              $integrationRepository,
        private readonly EntityManagerInterface             $em,
    ) {
    }

    /**
     * The provider list, as a Turbo Frame so a save can replace it in place.
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): Response
    {
        return $this->render('admin/_integrations_frame.html.twig', $this->listData());
    }

    #[Route('/{provider}/edit', name: 'edit', methods: ['GET'])]
    public function edit(Provider $provider): Response
    {
        return $this->render('admin/integrations/_form.html.twig', [
            'provider' => $provider,
            'config'   => $this->configRepository->findOneByProvider($provider),
        ]);
    }

    #[Route('/{provider}/save', name: 'save', methods: ['POST'])]
    public function save(Provider $provider, Request $request): Response
    {
        if (false === $this->isCsrfTokenValid('admin-integration-'.$provider->value, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $config = $this->configRepository->findOneByProvider($provider);

        if (null === $config) {
            $config = new IntegrationProviderConfig($provider);
            $this->em->persist($config);
        }

        $config->isEnabled = $request->request->getBoolean('isEnabled');
        $config->baseUrl = $this->nullIfBlank($request->request->get('baseUrl'));

        if (AuthKind::OAuth2 === $provider->authKind()) {
            $config->clientId = $this->nullIfBlank($request->request->get('clientId'));

            // A blank secret field means "leave it alone", not "clear it" —
            // the form never renders the stored value, so treating blank as a
            // deletion would silently wipe it on every unrelated edit. Clearing
            // is the explicit checkbox instead.
            $submittedSecret = $this->nullIfBlank($request->request->get('clientSecret'));

            if (true === $request->request->getBoolean('clearClientSecret')) {
                $config->clientSecret = null;
            } elseif (null !== $submittedSecret) {
                $config->clientSecret = $submittedSecret;
            }
        }

        $this->em->flush();

        return $this->render('admin/integrations/_saved.stream.html.twig', [
            ...$this->listData(),
            'toastMessage' => 'admin.integrations.saved',
        ], new Response(null, Response::HTTP_OK, ['Content-Type' => 'text/vnd.turbo-stream.html']));
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    private function listData(): array
    {
        return [
            'providers'  => Provider::cases(),
            'configs'    => $this->configRepository->findAllIndexedByProvider(),
            'userCounts' => $this->integrationRepository->countUsersByProvider(),
        ];
    }

    private function nullIfBlank(mixed $value): ?string
    {
        if (false === is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
