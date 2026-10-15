<?php

declare(strict_types=1);

namespace App\Tests\Form\Admin;

use App\Domain\Enum\Ai\AiProvider;
use App\Entity\Ai\AiSettings;
use App\Form\Admin\AiSettingsType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * What happens to a stored key when the form it sits in is saved.
 *
 * The key's field is never filled from storage, so it arrives empty on every
 * save that did not retype it. Empty therefore cannot mean "clear it" — and it
 * cannot mean "keep it" unconditionally either, because the address beside it
 * may have changed, and a key kept across that goes to a host it was never
 * issued for.
 */
final class AiConnectionKeyTest extends KernelTestCase
{
    private FormFactoryInterface $forms;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->forms = self::getContainer()->get('form.factory');
    }

    /** The ordinary save: nothing about the connection was touched. */
    public function testAnEmptyBoxKeepsTheKeyWhileTheAddressStays(): void
    {
        $settings = $this->submit('http://10.0.0.5:11434', '');

        self::assertSame('stored-key', $settings->apiToken);
    }

    /** Pasted with a slash, saved without one: the same host. */
    public function testATrailingSlashIsNotANewAddress(): void
    {
        $settings = $this->submit('http://10.0.0.5:11434/', '');

        self::assertSame('stored-key', $settings->apiToken);
    }

    /**
     * The case the rule exists for. Somebody changes the address and saves;
     * the box for the key is empty because it always is.
     */
    public function testAChangedAddressDropsTheKeyThatBelongedToTheOldOne(): void
    {
        $settings = $this->submit('https://somewhere.else.example/ollama', '');

        self::assertNull($settings->apiToken, 'a key issued for one host must not be sent to another');
    }

    /**
     * The provider is part of what a key was issued for.
     *
     * The same address under a different provider is, as far as plMail can
     * tell, a different thing answering — and the stored key was typed for the
     * one that was there before.
     */
    public function testAChangedProviderDropsTheKeyToo(): void
    {
        $settings = $this->submit('http://10.0.0.5:11434', '', 'openai');

        self::assertSame(AiProvider::OpenAi, $settings->provider);
        self::assertNull($settings->apiToken);
    }

    /** A new address with a new key typed beside it keeps the new key. */
    public function testATypedKeyIsTheKeyWhateverTheAddressDid(): void
    {
        self::assertSame('new-key', $this->submit('https://somewhere.else.example/ollama', 'new-key')->apiToken);
        self::assertSame('new-key', $this->submit('http://10.0.0.5:11434', 'new-key')->apiToken);
    }

    /**
     * The search host's key follows the search host's address, and only that.
     *
     * Two hosts, two keys, two addresses — and changing one address must not
     * cost the other host its key.
     */
    public function testEachHostsKeyFollowsItsOwnAddress(): void
    {
        $settings = $this->stored();
        $settings->embeddingOwnConnection = true;
        $settings->embeddingBaseUrl       = 'http://10.0.0.9:11434';
        $settings->embeddingApiToken      = 'search-key';

        $form = $this->forms->create(AiSettingsType::class, $settings, ['csrf_protection' => false]);
        $form->submit([
            'provider'               => 'ollama',
            'baseUrl'                => 'https://somewhere.else.example/ollama',
            'embeddingOwnConnection' => '1',
            'embeddingProvider'      => 'ollama',
            'embeddingBaseUrl'       => 'http://10.0.0.9:11434',
        ], false);

        self::assertNull($settings->apiToken, 'the writing host moved');
        self::assertSame('search-key', $settings->embeddingApiToken, 'the search host did not');

        $form = $this->forms->create(AiSettingsType::class, $settings, ['csrf_protection' => false]);
        $form->submit([
            'provider'               => 'ollama',
            'baseUrl'                => 'https://somewhere.else.example/ollama',
            'embeddingOwnConnection' => '1',
            'embeddingProvider'      => 'openai',
            'embeddingBaseUrl'       => 'http://10.0.0.9:11434',
        ], false);

        self::assertNull($settings->embeddingApiToken, 'a different provider at the search address is a different host');
    }

    /**
     * Switching the second host off and on again keeps its key.
     *
     * It is the same host with the same address; nothing about who the key was
     * issued for has changed. Only the address changing makes it somebody
     * else's.
     */
    public function testSwitchingTheSecondHostOffDoesNotForgetItsKey(): void
    {
        $settings = $this->stored();
        $settings->embeddingOwnConnection = true;
        $settings->embeddingBaseUrl       = 'http://10.0.0.9:11434';
        $settings->embeddingApiToken      = 'search-key';

        $form = $this->forms->create(AiSettingsType::class, $settings, ['csrf_protection' => false]);
        // A whole submission, as a browser sends one: an unticked box is a
        // field that is simply not there, and only clearing what is missing
        // reads that as "off".
        $form->submit([
            'provider'              => 'ollama',
            'baseUrl'               => 'http://10.0.0.5:11434',
            'embeddingProvider'     => 'ollama',
            'embeddingBaseUrl'      => 'http://10.0.0.9:11434',
            'semanticMinSimilarity' => '0.42',
            'holdMaxSeconds'        => '60',
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true, false));
        self::assertFalse($settings->embeddingOwnConnection);
        self::assertSame('search-key', $settings->embeddingApiToken);
    }

    /** Never rendered back, on the way in or on the way out. */
    public function testTheKeyIsNotPutBackOnThePage(): void
    {
        $settings = $this->stored();

        $form = $this->forms->create(AiSettingsType::class, $settings, ['csrf_protection' => false]);

        self::assertSame('', (string) $form->createView()->children['apiToken']->vars['value']);
    }

    private function submit(string $address, string $key, string $provider = 'ollama'): AiSettings
    {
        $settings = $this->stored();

        $form = $this->forms->create(AiSettingsType::class, $settings, ['csrf_protection' => false]);

        $form->submit(['provider' => $provider, 'baseUrl' => $address, 'apiToken' => $key], false);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true, false));

        return $settings;
    }

    private function stored(): AiSettings
    {
        $settings = new AiSettings();
        $settings->baseUrl  = 'http://10.0.0.5:11434';
        $settings->apiToken = 'stored-key';

        return $settings;
    }
}
