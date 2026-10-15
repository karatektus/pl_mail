<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\DTO\System\ChangelogRelease;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Mail\UserAccounts;
use App\Service\System\WhatsNew\FeedbackMailer;
use App\Service\System\WhatsNew\WhatsNew;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * What's new: the release notes dialog, its feedback form, and the switch that
 * keeps it quiet (#165).
 *
 * ── One fragment, drawn again ────────────────────────────────────────────────
 * Everything here answers with the whole dialog for the shared modal's frame.
 * Picking a release, opening the feedback form, sending it and ticking "Don't
 * show this after updates" all draw it again from the server, so the release
 * list, the selection and the footer are never out of step with each other,
 * and none of it needs a script.
 *
 * ── `since` travels in the links ─────────────────────────────────────────────
 * The first draw records the running release as seen (WhatsNew explains why
 * drawing counts). Every draw after it would then find nothing new, and "New
 * to you" would empty itself the moment somebody clicked a release in it. So
 * the first draw puts where this person stood into every link and form as
 * `since`, and later draws group by that. It only decides which heading a
 * release sits under; anybody may pass any value, and the worst it can do is
 * file their own notes under the wrong heading.
 */
#[IsGranted('ROLE_USER')]
final class WhatsNewController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly WhatsNew               $whatsNew,
        private readonly UserAccounts           $accounts,
        private readonly FeedbackMailer         $feedback,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/whats-new', name: 'app_whats_new', methods: ['GET'])]
    public function show(Request $request): Response
    {
        $user  = $this->user();
        $since = $this->since($request, $user);

        $this->whatsNew->markSeen($user);
        $this->em->flush();

        return $this->dialog($user, $since, (string) $request->query->get('release', ''), 'feedback' === $request->query->get('pane'));
    }

    #[Route('/whats-new/quiet', name: 'app_whats_new_quiet', methods: ['POST'])]
    public function quiet(Request $request): Response
    {
        $user = $this->user();

        $this->assertCsrf($request, 'whats-new');

        $user->whatsNewQuiet = '1' === (string) $request->request->get('quiet');
        $this->em->flush();

        return $this->dialog(
            $user,
            $this->since($request, $user),
            (string) $request->request->get('release', ''),
            'feedback' === $request->request->get('pane'),
        );
    }

    #[Route('/whats-new/feedback', name: 'app_whats_new_feedback', methods: ['POST'])]
    public function sendFeedback(Request $request): Response
    {
        $user = $this->user();

        $this->assertCsrf($request, 'whats-new');

        $since   = $this->since($request, $user);
        $rating  = (string) $request->request->get('rating', '');
        $rating  = in_array($rating, ['better', 'same', 'worse'], true) ? $rating : null;
        $message = trim((string) $request->request->get('message', ''));
        $account = $this->sendingAccount($user, (int) $request->request->get('account', 0));

        $shotUrl    = (string) $request->request->get('screenshot', '');
        $screenshot = '' === $shotUrl ? null : FeedbackMailer::decodeScreenshot($shotUrl);

        $form = ['rating' => $rating, 'message' => $message, 'account' => $account?->id];

        $error = match (true) {
            null === $account                    => 'whats_new.feedback.error.account',
            '' === $message && null === $rating  => 'whats_new.feedback.error.empty',
            '' !== $shotUrl && null === $screenshot => 'whats_new.feedback.error.screenshot',
            default                              => null,
        };

        if (null === $error && null !== $account && false === $this->feedback->send($account, $rating, $message, $screenshot)) {
            $error = 'whats_new.feedback.error.send';
        }

        return $this->dialog($user, $since, '', true, [
            'sent'  => null === $error,
            'error' => $error,
            'form'  => $form,
        ], null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** Settings → General → What's new: the way back from "Don't show this after updates". */
    #[Route('/settings/whats-new', name: 'app_settings_whats_new_update', methods: ['POST'])]
    public function settings(Request $request): Response
    {
        $user = $this->user();

        $this->assertCsrf($request, 'settings-whats-new');

        // Read only when posted, like every control on the settings pages.
        if (true === $request->request->has('show')) {
            $user->whatsNewQuiet = '1' !== (string) $request->request->get('show');
        }

        $this->em->flush();

        return $this->redirectToRoute('app_settings_index', ['section' => 'general']);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $feedback the feedback form's outcome, when one was posted
     */
    private function dialog(User $user, string $since, string $wanted, bool $feedbackPane, array $feedback = [], int $status = Response::HTTP_OK): Response
    {
        $history = $this->whatsNew->history();
        $fresh   = $this->whatsNew->since($since);

        $selected = null;

        foreach ($history as $release) {
            if ($release->tag() === $wanted || $release->version === $wanted) {
                $selected = $release;
            }
        }

        $selected ??= $fresh[0] ?? $history[0] ?? null;

        // With nothing new, the running release heads the list on its own, so
        // a dialog opened from the ? always starts on what is installed.
        $top = [] !== $fresh ? $fresh : array_slice($history, 0, 1);

        // By version, not identity: the changelog comes out of a cache, and two
        // reads of it are two sets of equal objects.
        $onTop = array_map(static fn (ChangelogRelease $release): string => $release->version, $top);
        $older = array_values(array_filter(
            $history,
            static fn (ChangelogRelease $release): bool => false === in_array($release->version, $onTop, true),
        ));

        $accounts = $this->accounts->active($user);

        return $this->render('whats_new/dialog.html.twig', [
            'since'        => $since,
            'top'          => $top,
            'topIsNew'     => [] !== $fresh,
            'older'        => $older,
            'olderOpen'    => null !== $selected && false === in_array($selected->version, $onTop, true),
            'selected'     => $selected,
            'feedbackPane' => $feedbackPane && [] !== $accounts,
            'accounts'     => $accounts,
            'recipient'    => $this->feedback->recipient(),
            'feedback'     => $feedback + ['sent' => false, 'error' => null, 'form' => []],
        ], new Response(status: $status));
    }

    /**
     * Where this person stood before the dialog was first drawn: from the
     * request when the first draw passed it on, else from the settings bag —
     * read before markSeen() moves it.
     */
    private function since(Request $request, User $user): string
    {
        $passed = (string) ($request->query->get('since') ?? $request->request->get('since') ?? '');

        return 1 === preg_match('/^\d+\.\d+\.\d+$/', $passed) ? $passed : $this->whatsNew->seenBy($user);
    }

    /** One of this person's own switched-on accounts, and nobody else's. */
    private function sendingAccount(User $user, int $id): ?Account
    {
        foreach ($this->accounts->active($user) as $account) {
            if ($account->id === $id) {
                return $account;
            }
        }

        return null;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
