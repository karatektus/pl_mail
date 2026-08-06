<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\Enum\PushDeliveryOutcome;
use App\Domain\Enum\PushTransport;
use App\Form\Admin\FcmConfigType;
use App\Repository\Push\PushDeliveryRepository;
use App\Service\Push\FcmConfigWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin → Push: the Firebase project this installation delivers Android
 * notifications through.
 *
 * One action for GET and POST, as the rest of the admin area does it: the form
 * has to re-render with what was typed when a paste is rejected, and a separate
 * GET could not do that — which matters more here than anywhere else, because
 * the thing being typed is a two-kilobyte JSON blob nobody wants to paste twice.
 *
 * A Turbo Frame rather than a modal, unlike the integration providers. Those
 * are a list of eleven services where the form is a detour; this is one form
 * that IS the section, and putting it behind a button would add a click to
 * every visit for no gain.
 *
 * Web Push is deliberately absent from this page. Its VAPID keys are env vars
 * generated once by `app:push:generate-vapid-keys`, and a settings screen that
 * showed them read-only beside an editable Firebase key would suggest they can
 * be changed here. The page says which transports are live; where each one is
 * configured is documented rather than implied.
 *
 * The delivery log lives beside the form in a frame of its own rather than
 * inside it, and that is the reason it is a second action. This frame is
 * configuration: the docblock on its template records that nothing may
 * re-render it, because a reload empties a field mid-paste — and a filterable
 * table is a thing people re-render constantly. Two frames means the filter
 * form reloads the table and cannot touch the two-kilobyte JSON blob somebody
 * is halfway through pasting above it.
 */
#[Route('/admin/push', name: 'app_admin_push_')]
#[IsGranted('ROLE_ADMIN')]
final class PushSettingsController extends AbstractController
{
    /**
     * Deliveries per page. Smaller than the log browser's, because one state
     * change fans out to every device a user owns and a busy install writes
     * these in bursts — fifty rows is roughly "the last few minutes" rather
     * than a page anybody reads to the bottom of.
     */
    private const int PER_PAGE = 50;

    public function __construct(
        private readonly FcmConfigWriter $writer,
        private readonly PushDeliveryRepository $deliveries,
    ) {}

    #[Route('', name: 'settings', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        $config = $this->writer->current();

        // The action has to be explicit. A Symfony form with no action submits
        // to the *document* URL, and this one renders inside a Turbo Frame — so
        // the POST would go to /admin?section=push and quietly do nothing. The
        // same trap IntegrationProviderController records.
        $form = $this->createForm(FcmConfigType::class, $config, [
            'action' => $this->generateUrl('app_admin_push_settings'),
        ]);
        $form->handleRequest($request);

        $saved = false;

        if (true === $form->isSubmitted() && true === $form->isValid()) {
            $this->writer->save($config, $form);

            // Re-created rather than reused: the key field is unmapped and
            // still holds what was pasted, and re-rendering it would put a
            // credential back on screen after it had been stored.
            $form  = $this->createForm(FcmConfigType::class, $config, [
                'action' => $this->generateUrl('app_admin_push_settings'),
            ]);
            $saved = true;
        }

        return $this->render('admin/push/_frame.html.twig', [
            'config' => $config,
            'form'   => $form,
            'saved'  => $saved,
        ]);
    }

    /**
     * What actually reached which device, newest first.
     *
     * Every filter is optional and every unparseable value becomes "no filter"
     * rather than an error: this is a diagnostic view reached by editing a
     * query string, and a 400 for `?transport=fcmm` would be a worse answer
     * than the unfiltered table.
     *
     * The two counts are asked for separately. `total` is what the filter
     * matches and drives the pager; `everything` is whether the table has ever
     * held anything at all, and it exists so the empty state can tell "no
     * deliveries match this filter" from "nothing has been pushed yet" — which
     * are opposite diagnoses and were the whole reason an admin came here.
     */
    #[Route('/deliveries', name: 'deliveries', methods: ['GET'])]
    public function deliveries(Request $request): Response
    {
        $userId    = (int) $request->query->get('usr', 0);
        $transport = PushTransport::tryFrom((string) $request->query->get('transport', ''));
        $outcome   = PushDeliveryOutcome::tryFrom((string) $request->query->get('outcome', ''));
        $page      = max(1, (int) $request->query->get('page', 1));

        $userId = $userId > 0 ? $userId : null;

        $total = $this->deliveries->countSearch($userId, $transport, $outcome);

        return $this->render('admin/push/_deliveries.html.twig', [
            'deliveries' => $this->deliveries->search($userId, $transport, $outcome, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total'      => $total,
            'everything' => null === $userId && null === $transport && null === $outcome
                ? $total
                : $this->deliveries->countSearch(null, null, null),
            'page'       => $page,
            'pages'      => max(1, (int) ceil($total / self::PER_PAGE)),
            'usr'        => $userId,
            'transport'  => $transport,
            'outcome'    => $outcome,
            'users'      => $this->deliveries->distinctUsers(),
            'transports' => PushTransport::cases(),
            'outcomes'   => PushDeliveryOutcome::cases(),
        ]);
    }
}
