<?php

namespace App\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Attribute\Route;

class DefaultController
{
    private ?Profiler $profiler;

    public function __construct(?Profiler $profiler)
    {
        $this->profiler = $profiler;
    }

    #[Route("", 'app_default_index')]
    #[Template('default/index.html.twig')]
    public function index(): Response
    {
        return new RedirectResponse(     '/mail/inbox');
    }

    /**
     * @Route("/runtime.js")
     * @Template("default_runtime.js.twig")
     */
    public function runtime(): array
    {
        $this->profiler?->disable();

        return [];
    }
}
