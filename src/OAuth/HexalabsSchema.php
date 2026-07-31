<?php

namespace Hexalabs\BillingBridge\OAuth;

use App\Extensions\OAuth\Schemas\OAuthSchema;
use BackedEnum;
use Filament\Forms\Components\TextInput;

/**
 * Registers the HexaLabs billing app as an OAuth identity provider for the
 * panel. Pair with `is_managed_externally` on created users so email and
 * username self-service stays locked and the two sides cannot drift.
 */
class HexalabsSchema extends OAuthSchema
{
    public function getId(): string
    {
        return 'hexalabs';
    }

    public function getName(): string
    {
        return 'HexaLabs Account';
    }

    public function getSocialiteProvider(): ?string
    {
        return HexalabsProvider::class;
    }

    public function getIcon(): null|string|BackedEnum
    {
        return 'tabler-user-circle';
    }

    public function getHexColor(): ?string
    {
        return '#00d4ff';
    }

    public function getServiceConfig(): array
    {
        return array_merge(parent::getServiceConfig(), [
            'base_url' => env('OAUTH_HEXALABS_BASE_URL'),
        ]);
    }

    public function getSettingsForm(): array
    {
        return array_merge([
            TextInput::make('OAUTH_HEXALABS_BASE_URL')
                ->label('Billing app URL')
                ->placeholder('https://billing.hexalabshosting.fr')
                ->helperText('Root URL of the billing app; /oauth/authorize and /api/user hang off it.')
                ->columnSpan(4)
                ->required()
                ->url()
                ->default(env('OAUTH_HEXALABS_BASE_URL')),
        ], parent::getSettingsForm());
    }
}
