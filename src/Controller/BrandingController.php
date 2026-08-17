<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Enum\Theme\LogoStyle;
use App\Entity\User\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The mark, served in the colourway the viewer chose.
 *
 * A favicon is a static file for a product whose mark has one face; plMail's
 * has thirty-two now (Settings → Appearance → Logo), and a <link> can only
 * point somewhere — so it points here, and here answers with the SVG painted
 * for whoever is asking. Anonymous requests get the product default, which is
 * also what public/icons/favicon.svg holds: the static file stays as the
 * fallback for anything that will not send a cookie, and this route is why
 * the two can never disagree for a signed-in user.
 *
 * Cache-Control private: the answer depends on the session, and a shared
 * cache holding one user's berry for another's ink is exactly the bug a
 * favicon cannot visibly report. Revalidated by ETag rather than aged out —
 * the tag IS the style value, so an unchanged choice costs a 304 and a
 * changed one is visible on the very next page, instead of whenever a
 * max-age happened to expire.
 */
final class BrandingController extends AbstractController
{
    #[Route('/branding/favicon.svg', name: 'app_branding_favicon')]
    public function favicon(Request $request): Response
    {
        $user = $this->getUser();

        $style = $user instanceof User
            ? $user->appearance->logoStyle
            : LogoStyle::DEFAULT;

        $response = new Response(headers: [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, no-cache',
        ]);
        $response->setEtag($style->value);

        if ($response->isNotModified($request)) {
            return $response;
        }

        $response->setContent($this->renderView('branding/favicon.svg.twig', ['style' => $style]));

        return $response;
    }
}
