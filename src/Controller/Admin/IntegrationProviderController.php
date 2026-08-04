<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Integration\AuthKind;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Enum\Integration\ServiceKind;
use App\Entity\Integration\IntegrationProviderConfig;
use App\Entity\Integration\MailProviderConfig;
use App\Form\Integration\IntegrationProviderConfigType;
use App\Form\Integration\MailProviderConfigType;
use App\Repository\Integration\IntegrationProviderConfigRepository;
use App\Repository\Integration\IntegrationRepository;
use App\Repository\Integration\MailProviderConfigRepository;
use App\Service\Integration\ProviderConfigWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
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
        private readonly MailProviderConfigRepository       $mailConfigRepository,
        private readonly EntityManagerInterface             $em,
        private readonly ProviderConfigWriter               $configWriter,
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

    /**
     * The provider's setup form, and its submission.
     *
     * One action for both, as the rest of the app does it: the form re-renders
     * itself with what was typed when a submission is rejected, which a separate
     * GET could not do.
     */
    #[Route('/{provider}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Provider $provider, Request $request): Response
    {
        $config = $this->configRepository->findOneByProvider($provider) ?? new IntegrationProviderConfig($provider);

        // The action has to be explicit. A Symfony form with no action submits
        // to the *document* URL, and this one renders inside a Turbo Frame — so
        // the POST went to /admin?section=integrations and quietly did nothing.
        $form = $this->createForm(IntegrationProviderConfigType::class, $config, [
            'integration_provider' => $provider,
            'action'               => $this->generateUrl('app_admin_integrations_edit', ['provider' => $provider->value]),
        ]);
        $form->handleRequest($request);

        if (true === $form->isSubmitted() && true === $form->isValid()) {
            $this->configWriter->saveIntegrationProvider($config, $form);

            return $this->savedStream('admin.integrations.saved');
        }

        return $this->render('admin/integrations/_form.html.twig', [
            'provider' => $provider,
            'config'   => $config,
            'form'     => $form,
        ]);
    }

    #[Route('/mail/{provider}/edit', name: 'mail_edit', methods: ['GET', 'POST'])]
    public function editMail(MailProvider $provider, Request $request): Response
    {
        $config = $this->mailConfigRepository->findOneByProvider($provider) ?? new MailProviderConfig($provider);

        $form = $this->createForm(MailProviderConfigType::class, $config, [
            'mail_provider' => $provider,
            'action'        => $this->generateUrl('app_admin_integrations_mail_edit', ['provider' => $provider->value]),
        ]);
        $form->handleRequest($request);

        if (true === $form->isSubmitted() && true === $form->isValid()) {
            $this->configWriter->saveMailProvider($config, $form);

            return $this->savedStream('admin.integrations.mail.saved');
        }

        return $this->render('admin/integrations/_mail_form.html.twig', [
            'provider' => $provider,
            'config'   => $config,
            'form'     => $form,
        ]);
    }

    /**
     * Which integration can borrow which mail provider's app registration.
     *
     * Google Drive and Photos live in the same Cloud project as Gmail, and
     * OneDrive is the same Entra app registration as Graph mail — so the client
     * id and secret genuinely are the same credential. Dropbox has no mail
     * counterpart and is absent.
     */
    /** Shared with the setup wizard; see ProviderConfigWriter. */
    private const array INHERITABLE = ProviderConfigWriter::INHERITABLE;

    #[Route('/{provider}/inherit', name: 'inherit', methods: ['POST'])]
    public function inherit(Provider $provider, Request $request): Response
    {
        if (false === $this->isCsrfTokenValid('admin-integration-inherit-'.$provider->value, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $source = $this->inheritableSource($provider);

        if (null === $source || false === $source->isComplete()) {
            throw $this->createNotFoundException();
        }

        $config = $this->configRepository->findOneByProvider($provider);

        if (null === $config) {
            $config = new IntegrationProviderConfig($provider);
            $this->em->persist($config);
        }

        $config->clientId = $source->clientId;
        $config->clientSecret = $source->clientSecret;

        $this->em->flush();

        return $this->savedStream('admin.integrations.inherited');
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The mail config an integration may inherit from, or null when there is no
     * counterpart or it is not configured.
     */
    private function inheritableSource(Provider $provider): ?MailProviderConfig
    {
        $mailProvider = MailProvider::tryFrom(self::INHERITABLE[$provider->value] ?? '');

        return null === $mailProvider
            ? null
            : $this->mailConfigRepository->findOneByProvider($mailProvider);
    }

    /**
     * The write-only secret rule, in one place.
     *
     * The form never renders a stored secret, so an empty submission means
     * "leave it alone" — mapping the field would wipe it every time an admin
     * edited something else. Clearing is the explicit checkbox, which only
     * exists when there is something to clear.
     *
     * @param callable(?string):void $assign
     */
    private function savedStream(string $message): Response
    {
        return $this->render('admin/integrations/_saved.stream.html.twig', [
            ...$this->listData(),
            'toastMessage' => $message,
        ], new Response(null, Response::HTTP_OK, ['Content-Type' => 'text/vnd.turbo-stream.html']));
    }


    /**
     * @return array<string,mixed>
     */
    private function listData(): array
    {
        return [
            'providers'     => Provider::of(ServiceKind::Files),
            'configs'       => $this->configRepository->findAllIndexedByProvider(),
            'userCounts'    => $this->integrationRepository->countUsersByProvider(),
            'mailProviders' => MailProvider::cases(),
            'mailConfigs'   => $this->mailConfigRepository->findAllIndexedByProvider(),
            // Which integrations could inherit credentials from which mail
            // provider. Drives the "reuse" button, and stays here rather than in
            // the template so the copy endpoint validates against the same map.
            'inheritable'   => self::INHERITABLE,
        ];
    }

}
