<?php

declare(strict_types=1);

namespace App\Tests\Service\Oidc;

use App\Entity\Oidc\OidcConfig;
use App\Repository\Oidc\OidcConfigRepository;
use App\Service\Oidc\OidcSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What an administrator saved is in force when it is whole, and the
 * environment when it is not — one or the other, never a field from each.
 *
 * The case that matters is the half-saved row. Issuer, client id and secret
 * are one provider's registration; a row holding an issuer and no secret that
 * shadowed the environment would switch a working sign-in off, and one that
 * borrowed the environment's secret would send it to a provider it was never
 * issued for.
 */
final class OidcSettingsTest extends TestCase
{
    public function testAWholeRowWinsOverTheEnvironmentOptionsIncluded(): void
    {
        $row                       = new OidcConfig();
        $row->issuer               = 'https://admin.example.com/';
        $row->clientId             = 'admin-client';
        $row->clientSecret         = 'admin-secret';
        $row->autoCreate           = true;
        $row->requireVerifiedEmail = false;

        $settings = $this->settings($row);
        $current  = $settings->current();

        self::assertSame(OidcSettings::SOURCE_ADMIN, $settings->source());
        self::assertSame(['https://admin.example.com/', 'admin-client', 'admin-secret'], [$current->issuer, $current->clientId, $current->clientSecret]);
        self::assertTrue($current->autoCreate);
        self::assertFalse($current->requireVerifiedEmail);
        self::assertSame(['openid', 'email', 'profile'], $current->scopes, 'no scopes saved asks for the default set');
    }

    public function testAHalfSavedRowLeavesTheEnvironmentInForceWhole(): void
    {
        $row           = new OidcConfig();
        $row->issuer   = 'https://admin.example.com/';
        $row->clientId = 'admin-client';

        $settings = $this->settings($row);
        $current  = $settings->current();

        self::assertSame(OidcSettings::SOURCE_ENVIRONMENT, $settings->source());
        self::assertSame(['https://env.example.com/', 'env-client', 'env-secret'], [$current->issuer, $current->clientId, $current->clientSecret]);
        self::assertTrue($current->requireVerifiedEmail, 'a blank variable must not switch the check off');
    }

    public function testPasswordLoginStaysOnUnlessAWholeProviderIsInForceAndSaysOtherwise(): void
    {
        self::assertTrue($this->settings(null)->current()->allowsPasswordLogin());

        $row                        = new OidcConfig();
        $row->issuer                = 'https://admin.example.com/';
        $row->clientId              = 'admin-client';
        $row->clientSecret          = 'admin-secret';
        $row->passwordLoginDisabled = true;

        self::assertFalse($this->settings($row)->current()->allowsPasswordLogin());

        $row->clientSecret = null;
        self::assertTrue(
            $this->settings($row)->current()->allowsPasswordLogin(),
            'a half-saved provider is ignored, and the box ticked beside it must not lock anybody out',
        );
    }

    public function testTheSettingIsInertWithoutAProvider(): void
    {
        $configs = self::createStub(OidcConfigRepository::class);
        $settings = new OidcSettings($configs, '', '', '', '', false, '', 'openid email', 'true');

        self::assertTrue($settings->current()->allowsPasswordLogin(), 'no provider, so the password form is the only way in and stays');
    }

    /**
     * Only a value that plainly says yes locks the form: this setting takes
     * people's way in away, so a typo has to land on "still there".
     */
    #[DataProvider('environmentValues')]
    public function testTheEnvironmentSwitchesItOffOnlyOnAClearYes(string $value, bool $passwordAllowed): void
    {
        $configs = self::createStub(OidcConfigRepository::class);
        $settings = new OidcSettings($configs, 'https://env.example.com/', 'env-client', 'env-secret', '', false, '', 'openid email', $value);

        self::assertSame($passwordAllowed, $settings->current()->allowsPasswordLogin());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function environmentValues(): iterable
    {
        yield 'true' => ['true', false];
        yield 'on, any case' => [' On ', false];
        yield '1' => ['1', false];
        yield 'blank' => ['', true];
        yield 'false' => ['false', true];
        yield 'a typo' => ['ture', true];
    }

    private function settings(?OidcConfig $row): OidcSettings
    {
        $configs = self::createStub(OidcConfigRepository::class);
        $configs->method('current')->willReturn($row);

        return new OidcSettings($configs, 'https://env.example.com/', 'env-client', 'env-secret', '', false, '', 'openid email');
    }
}
