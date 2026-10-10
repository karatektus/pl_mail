<?php

declare(strict_types=1);

namespace App\Tests\Form\Admin;

use App\Entity\Ai\AiSettings;
use App\Form\Admin\AiSettingsType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

final class AiConnectionHeadersFormTest extends KernelTestCase
{
    private function submit(AiSettings $settings, array $headers, ?string $endpoint = null, ?string $revision = null): \Symfony\Component\Form\FormInterface
    {
        self::bootKernel();
        $form = self::getContainer()->get(FormFactoryInterface::class)->create(AiSettingsType::class, $settings, ['csrf_protection' => false]);
        $data = ['chatProvider' => 'openai', 'openAiBaseUrl' => $endpoint ?? $settings->openAiBaseUrl,
            'openAiModel' => 'synthetic-chat', 'embeddingSharedConnection' => '1', 'embeddingProvider' => 'openai',
            'embeddingModel' => 'synthetic-embedding', 'semanticMinSimilarity' => '0.5', 'holdMaxSeconds' => '60',
            'openAiHeaders' => $headers, 'embeddingRevision' => $revision ?? $settings->embeddingRevision];
        $form->submit($data);
        return $form;
    }

    public function testBlankPreservesSecretAndCredentialRotationPreservesVectorSpace(): void
    {
        $s = new AiSettings(); $s->chatProvider = 'openai'; $s->openAiBaseUrl = 'https://synthetic.test/v1';
        $s->embeddingSharedConnection = true; $s->embeddingProvider = 'openai'; $s->embeddingModel = 'synthetic-embedding';
        $s->openAiHeaders = '{"X-Tenant":"synthetic-secret"}';
        $old = $s->embeddingSpace();
        $form = $this->submit($s, [['name' => 'X-Tenant', 'mode' => 'fixed', 'value' => '']]);
        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame('{"X-Tenant":"synthetic-secret"}', $s->openAiHeaders);
        self::assertSame('', $form->createView()['openAiHeaders'][0]['value']->vars['value']);
        self::assertSame($old, $s->embeddingSpace());
        self::ensureKernelShutdown();
        $form = $this->submit($s, [['name' => 'X-Tenant', 'mode' => 'fixed', 'value' => 'replacement-synthetic-secret']]);
        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame($old, $s->embeddingSpace()); self::assertFalse($s->embeddingReindexRequired);
        self::ensureKernelShutdown();
        $form = $this->submit($s, [['name' => 'X-Session', 'mode' => 'session', 'value' => '']]);
        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame($old, $s->embeddingSpace()); self::assertFalse($s->embeddingReindexRequired);
        self::ensureKernelShutdown();
        $form = $this->submit($s, [['name' => 'X-Session', 'mode' => 'session', 'value' => '']], revision: 'explicit-routing-change');
        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertNotSame($old, $s->embeddingSpace()); self::assertTrue($s->embeddingReindexRequired);
    }

    public function testEndpointChangeAndExplicitRowRemovalDiscardOldCredentials(): void
    {
        $s = new AiSettings(); $s->openAiBaseUrl = 'https://synthetic.test/v1'; $s->openAiHeaders = '{"X-Tenant":"synthetic-secret"}';
        $form = $this->submit($s, [['name' => 'X-Tenant', 'mode' => 'fixed', 'value' => '']], 'https://other.test/v1');
        self::assertTrue($form->isValid(), (string) $form->getErrors(true)); self::assertNull($s->openAiHeaders);
        self::ensureKernelShutdown();
        $s->openAiHeaders = '{"X-Tenant":"synthetic-secret"}';
        $form = $this->submit($s, []);
        self::assertTrue($form->isValid(), (string) $form->getErrors(true)); self::assertNull($s->openAiHeaders);
    }

    public function testDuplicateNamesAndControlCharactersProduceGenericErrors(): void
    {
        $s = new AiSettings(); $s->openAiBaseUrl = 'https://synthetic.test/v1';
        $form = $this->submit($s, [['name' => 'X-A', 'mode' => 'fixed', 'value' => 'synthetic-secret'], ['name' => 'x-a', 'mode' => 'fixed', 'value' => 'another-secret']]);
        self::assertFalse($form->isValid()); self::assertStringNotContainsString('synthetic-secret', (string) $form->getErrors(true));
        self::ensureKernelShutdown();
        $form = $this->submit($s, [['name' => 'X-A', 'mode' => 'fixed', 'value' => "secret\r\nHost: attack"]]);
        self::assertFalse($form->isValid()); self::assertStringNotContainsString('attack', (string) $form->getErrors(true));
    }
    public function testEmptyNewRowIsIgnoredAndPartialRowHasInlineError(): void
    {
        $s = new AiSettings(); $s->openAiBaseUrl = 'https://synthetic.test/v1';
        $form = $this->submit($s, [['name' => '', 'mode' => 'session', 'value' => '']]);
        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertNull($s->openAiHeaders);
        self::ensureKernelShutdown();
        $form = $this->submit($s, [['name' => 'X-New', 'mode' => 'fixed', 'value' => '']]);
        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get('openAiHeaders')->get('0')->get('value')->getErrors());
        self::ensureKernelShutdown();
        $form = $this->submit($s, [['name' => 'X-New', 'mode' => 'fixed', 'value' => '']], 'https://other.test/v1');
        self::assertFalse($form->isValid());
        self::ensureKernelShutdown();
        $form = $this->submit($s, [['name' => '', 'mode' => 'fixed', 'value' => 'synthetic']]);
        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get('openAiHeaders')->get('0')->get('name')->getErrors());
    }
}
