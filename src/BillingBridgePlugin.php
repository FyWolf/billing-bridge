<?php

namespace Hexalabs\BillingBridge;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Deliberately inert.
 *
 * The panel's plugin loader requires a class implementing Filament's Plugin
 * contract, but this bridge registers no resources, pages or widgets —
 * `"panels": []` in plugin.json keeps it out of every panel. That is the whole
 * point: with no Filament surface there is nothing to break when the panel
 * bumps a Filament major, which is what happened to the in-panel billing
 * plugin.
 *
 * All real work happens in the service providers under src/Providers.
 */
class BillingBridgePlugin implements Plugin
{
    public function getId(): string
    {
        return 'billing-bridge';
    }

    public function register(Panel $panel): void
    {
        //
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
