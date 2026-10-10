<?php

declare(strict_types=1);

namespace App\Tests\Form\Admin;

use App\Entity\Ai\AiSettings;
use App\Form\Admin\AiSettingsType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

final class AiProviderSettingsTest extends KernelTestCase
{
    public function testSavedSecretIsNotRenderedAndCannotFollowAnEndpointChange(): void
    {
        self::bootKernel();
        $settings = new AiSettings();
        $settings->chatProvider = 'openai';
        $settings->openAiBaseUrl = 'https://synthetic.test/v1';
        $settings->openAiApiToken = 'synthetic-key';
        $factory = self::getContainer()->get(FormFactoryInterface::class);
        $form = $factory->create(AiSettingsType::class, $settings, ['csrf_protection'=>false]);
        self::assertSame('', $form->createView()->children['openAiApiToken']->vars['value']);
        $form->submit(['semanticMinSimilarity'=>'0.42', 'holdMaxSeconds'=>'60', 'chatProvider'=>'openai', 'openAiBaseUrl'=>'https://other-synthetic.test/v1']);
        self::assertNull($settings->openAiApiToken);
        self::assertSame('', $form->createView()->children['openAiApiToken']->vars['value']);
    }

    public function testOldConfigurationKeepsOllamaAndSameEndpointKeepsItsSecret(): void
    {
        self::bootKernel();
        $settings = new AiSettings();
        self::assertSame('ollama', $settings->chatProvider);
        $settings->openAiBaseUrl = 'https://synthetic.test/v1';
        $settings->openAiApiToken = 'synthetic-key';
        $form = self::getContainer()->get(FormFactoryInterface::class)->create(AiSettingsType::class, $settings, ['csrf_protection'=>false]);
        $form->submit(['semanticMinSimilarity'=>'0.42', 'holdMaxSeconds'=>'60', 'chatProvider'=>'ollama', 'openAiBaseUrl'=>'https://synthetic.test/v1']);
        self::assertSame('synthetic-key', $settings->openAiApiToken);
    }
}
