<?php

declare(strict_types=1);

namespace App\Application\Identity;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

final readonly class TwoFactorQrCode
{
    public function __construct(
        private Google2FA $google2fa,
    ) {
    }

    public function dataUri(string $email, string $secret): string
    {
        $configuredIssuer = config('app.name', 'artifactflow');
        $issuer = is_string($configuredIssuer) && $configuredIssuer !== '' ? $configuredIssuer : 'artifactflow';
        $payload = $this->google2fa->getQRCodeUrl($issuer, $email, $secret);
        $renderer = new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd());
        $writer = new Writer($renderer);

        return 'data:image/svg+xml;base64,' . base64_encode($writer->writeString($payload));
    }
}
