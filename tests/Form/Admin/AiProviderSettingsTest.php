<?php

declare(strict_types=1);

namespace App\Tests\Form\Admin;

use App\Entity\Ai\AiSettings;
use App\Form\Admin\AiSettingsType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

final class AiProviderSettingsTest extends KernelTestCase
{
    public function testEmbeddingEndpointChangeRequiresApprovalAndClearsOnlyItsSecret(): void
    {
        self::bootKernel();
        $settings = new AiSettings();
        $settings->embeddingProvider = 'openai';
        $settings->embeddingBaseUrl = 'https://old.synthetic.test/v1';
        $settings->embeddingApiToken = 'synthetic-embedding-secret';
        $settings->embeddingModel = 'embedding-model';
        $settings->openAiApiToken = 'synthetic-generation-secret';
        $settings->openAiBaseUrl = 'https://generation.synthetic.test/v1';
        $old = $settings->embeddingSpace();
        $form = self::getContainer()->get(FormFactoryInterface::class)->create(AiSettingsType::class, $settings, ['csrf_protection'=>false]);
        $form->submit(['chatProvider'=>'openai', 'openAiBaseUrl'=>$settings->openAiBaseUrl, 'embeddingProvider'=>'openai', 'embeddingBaseUrl'=>'https://new.synthetic.test/v1', 'embeddingModel'=>'embedding-model', 'semanticMinSimilarity'=>'0.42', 'holdMaxSeconds'=>'60']);
        self::assertTrue($form->isValid());
        self::assertNull($settings->embeddingApiToken);
        self::assertSame('synthetic-generation-secret', $settings->openAiApiToken);
        self::assertSame($old, $settings->embeddingApprovedSpace);
        self::assertTrue($settings->embeddingReindexRequired);
        self::assertFalse($settings->embeddingSpaceApproved());
        self::assertSame('', $form->createView()->children['embeddingApiToken']->vars['value']);
    }

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
