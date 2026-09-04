<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\Message;
use App\Entity\Monitoring\CategoryReport;
use App\Entity\User\User;
use App\Security\Voter\OwnershipVoter;
use App\Service\Mail\CategoryReportRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "This is in the wrong tab", from the person who can see that it is.
 *
 * WHY THIS EXISTS AT ALL. Categorisation is decided by three things that
 * disagree — a provider's labels, a header cascade, and a model — and whether
 * the answer is any good is a judgement only the owner of the mailbox can make.
 * Everything else in this feature is measurable against fixtures somebody
 * invented; this is the one route by which real mail, wrongly filed, becomes
 * something a rule or a prompt can be changed on the strength of.
 *
 * It records the DECISION and its evidence rather than the message: what each
 * of the three said, which bulk headers were present, who sent it and what it
 * was about. See {@see CategoryReport} for what is deliberately not kept.
 *
 * A POST with a token, like every other action that writes: the report names a
 * correspondent and a subject, so a link that could be followed from anywhere
 * would be a way to have somebody file their own mail into an administrator's
 * list without noticing.
 */
#[Route('/mail/message/{id}/category-report', name: 'app_mail_category_report', requirements: ['id' => '\d+'], methods: ['POST'])]
#[IsGranted('IS_AUTHENTICATED')]
final class CategoryReportController extends AbstractController
{
    public function __construct(private readonly CategoryReportRecorder $recorder)
    {
    }

    public function __invoke(Request $request, Message $message, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();

        if (false === $user instanceof User) {
            return new Response(status: Response::HTTP_CONFLICT);
        }

        if (false === $this->isCsrfTokenValid('category_report', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // The same ownership check the reading pane makes about the same
        // message: a report is written from what the reader can already see,
        // and must not become a way to ask about mail they cannot.
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message->account);

        $shouldBe = MessageCategory::tryFrom((string) $request->request->get('shouldBe'));

        if (null === $shouldBe) {
            // The control is a select over a closed set, so anything else
            // arrived by hand. Refused rather than guessed: a report filed
            // against a category nobody chose is worse than no report.
            return new Response(status: Response::HTTP_BAD_REQUEST);
        }

        $report = $this->recorder->record($user, $message, $shouldBe);

        // ALREADY THERE, so there is nothing to report.
        //
        // The page offers every tab EXCEPT the one the conversation is in, so
        // a reader can only ever choose a different one — but the report is
        // written against the category as it stands when the button is pressed,
        // and a re-sort or a re-classification between those two moments moves
        // it. What lands then is a row saying a message should be where it
        // already is: true, and carrying nothing.
        //
        // Cheap to guard and worth guarding, because a handful of these in a
        // list of twenty is enough to make somebody distrust the whole list.
        //
        // 409 rather than 400: the request was well formed and the reader did
        // nothing wrong. Their page is behind, which is what the card says
        // back to them.
        if ($report->filed === $shouldBe) {
            return new Response(status: Response::HTTP_CONFLICT);
        }

        $em->persist($report);
        $em->flush();

        // 204 and no redirect: this is pressed inside a popover on a thread the
        // reader is in the middle of, and navigating them away from it to
        // acknowledge a button would cost more than the button is worth. The
        // control confirms itself.
        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
