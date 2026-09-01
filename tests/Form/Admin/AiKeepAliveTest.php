<?php

declare(strict_types=1);

namespace App\Tests\Form\Admin;

use App\Entity\Ai\AiSettings;
use App\Form\Admin\AiSettingsType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * The keep-alive boxes are the last place a wrong duration can be caught.
 *
 * After this the value goes into a request body and the only symptom of a bad
 * one is that the writing help quietly stopped working: Ollama answers 400 for
 * a duration it cannot parse, on somebody else's request, hours later. So the
 * refusal has to happen here, at the moment the person who typed it is still
 * looking at it — and it has to be a refusal rather than a silent fallback to
 * the default, which would leave the field saying one thing while the host was
 * told another.
 *
 * Empty is the case worth stating out loud: it is not an unfinished field, it
 * is the explicit instruction to send no keep_alive at all and let whatever the
 * operator put in OLLAMA_KEEP_ALIVE on the host stand.
 */
final class AiKeepAliveTest extends KernelTestCase
{
    private FormFactoryInterface $forms;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->forms = self::getContainer()->get('form.factory');
    }

    public function testTheAcceptedSpellingsAreAccepted(): void
    {
        foreach (['5m', '30m', '4h', '3600', '0', '-1', ''] as $value) {
            $form = $this->submit($value);

            self::assertTrue($form->isValid(), $value . ' → ' . (string) $form->getErrors(true, false));
        }
    }

    /**
     * "5 min" is what somebody types who has not read the help text, and
     * "5M" is what somebody types who has — Go's duration parser accepts
     * neither, so neither may be accepted here.
     */
    public function testAnythingElseIsRefusedRatherThanQuietlyReplaced(): void
    {
        foreach (['5 min', '5M', 'forever', '5m30s', '-2'] as $value) {
            $form = $this->submit($value);

            self::assertFalse($form->isValid(), $value . ' was accepted');
        }
    }

    private function submit(string $keepAlive): FormInterface
    {
        $form = $this->forms->create(AiSettingsType::class, new AiSettings(), ['csrf_protection' => false]);

        $form->submit([
            'baseUrl'        => 'http://10.0.0.5:11434',
            'chatKeepAlive'  => $keepAlive,
        ], false);

        return $form;
    }
}
