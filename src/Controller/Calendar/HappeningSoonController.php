<?php

declare(strict_types=1);

namespace App\Controller\Calendar;

use App\Entity\User\User;
use App\Service\Calendar\HappeningSoonReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Happening Soon" — the panel behind the topbar's calendar indicator.
 *
 * **Where this lives is the decision worth recording**, because two other
 * shapes were tried on paper first and both are worse.
 *
 * A page of its own was rejected. The value of this list is that it answers
 * "what is coming up?" without making anybody leave what they are doing, and a
 * page does the opposite: it costs a navigation, it takes the mail off screen,
 * and it is a destination nobody would think to visit — there is no moment in
 * reading mail at which a person decides to go and look at a page about their
 * bookings.
 *
 * A panel in the mailbox was rejected for the cost it puts on everyone else.
 * The mail layout is already three panes on a wide screen and one on a phone,
 * with the calendar docking into that row; a fourth region would have to take
 * width from panes that have none to give, and it would render — and query — on
 * every mailbox load for a list that is empty for most users on most days.
 *
 * So it opens from the topbar, into the app's shared body-level modal frame.
 * The indicator beside the calendar button already answers "is there a reason to
 * look?"; until now the only thing it could do with a yes was colour a dot, and
 * the calendar button next to it opens a calendar rather than the answer. This
 * is the thing that indicator should have been able to open, and it is one
 * click from anywhere in the app because the topbar is on every page.
 *
 * The modal frame rather than a popover for the reason the event editor gives at
 * length: the panes carry backdrop-filter, which makes them containing blocks
 * for position:fixed, so anything anchored inside one is clipped to it. The
 * editor learned that the hard way and everything since renders into #modal.
 *
 * A real route rather than markup inlined in the topbar, and a GET at that: the
 * panel is a page's worth of query and it must not be paid for on renders where
 * nobody opens it. It is also then linkable, which is what makes a browser test
 * of it possible without driving the dialog.
 */
#[Route('/calendar', name: 'app_calendar_')]
#[IsGranted('IS_AUTHENTICATED')]
final class HappeningSoonController extends AbstractController
{
    public function __construct(
        private readonly HappeningSoonReader $reader,
    ) {
    }

    /**
     * A controller of its own rather than a fifth action on CalendarController.
     *
     * That class is about the calendar in its two shapes — a docked pane and a
     * page — and every action on it resolves a view, a range and an anchor
     * date. This resolves none of those: it is one list over a fixed window,
     * with no date in the URL and nothing to page through. Sharing the route
     * prefix is all the two have in common, and that is what the prefix is for.
     */
    #[Route('/soon', name: 'soon', methods: ['GET'])]
    public function panel(): Response
    {
        return $this->render('calendar/_happening_soon.html.twig', [
            'rows' => $this->reader->read($this->currentUser()),
        ]);
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        if (false === $user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
