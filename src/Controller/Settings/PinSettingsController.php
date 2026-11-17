<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Settings → Inbox → Message list → Pinned conversations (#654).
 *
 * One form for the three choices, posted whole on every change; each field is
 * read only when it was sent, so a hand-made post of one field leaves the
 * other two alone.
 */
#[IsGranted('ROLE_USER')]
final class PinSettingsController extends AbstractController
{
    use ChecksCsrf;

    /**
     * The spans the select offers by name. Must match the template's list:
     * a stored value among these is drawn as that option, anything else as
     * "a number of days".
     *
     * @var list<int>
     */
    public const array PRESET_DAYS = [1, 3, 7, 14, 30];

    /**
     * What "a number of days" starts at when it is first picked. Ten, not a
     * week: a preset would be drawn as that preset, and the day field would
     * vanish again the moment it was asked for.
     */
    private const int CUSTOM_DAYS_DEFAULT = 10;

    #[Route('/settings/pinning', name: 'app_settings_pinning_update', methods: ['POST'])]
    public function update(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $this->assertCsrf($request, 'settings-pinning');

        $form = $request->request;

        if (true === $form->has('reminderAt') && true === is_numeric($form->get('reminderAt'))) {
            $user->pinReminderAt = (int) $form->get('reminderAt');
        }

        if (true === $form->has('duration')) {
            $user->pinDurationDays = $this->days($form->getString('duration'), $form->getString('durationDays'), $user->pinDurationDays);
        }

        if (true === $form->has('keepOnMove')) {
            $user->pinKeepOnMove = '1' === $form->getString('keepOnMove');
        }

        $em->flush();

        return $this->redirect(
            $this->generateUrl('app_settings_page', ['page' => 'message-list']) . '#card-pinning',
            Response::HTTP_SEE_OTHER,
        );
    }

    /**
     * The select's answer as a number of days, null for forever.
     *
     * "custom" takes the day field when it came with the post. When it did not
     * — the select was just switched to it, and the field is not drawn yet —
     * it keeps the days already set if they are not a preset, or starts at
     * ten, so the field appears with a number in it rather than empty.
     */
    private function days(string $duration, string $field, ?int $current): ?int
    {
        if ('' === $duration) {
            return null;
        }

        if ('custom' !== $duration) {
            return true === ctype_digit($duration) ? (int) $duration : $current;
        }

        if (true === ctype_digit($field) && 0 < (int) $field) {
            return (int) $field;
        }

        return null !== $current && false === in_array($current, self::PRESET_DAYS, true)
            ? $current
            : self::CUSTOM_DAYS_DEFAULT;
    }
}
