<?php

use App\Support\HalfBlockQrOutput;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

it('renders a compact high-contrast half-block QR code with a 2-module quiet zone', function () {
    $ansiQr = HalfBlockQrOutput::forUrl('https://feat-x.203-0-113-10.sslip.io');

    expect($ansiQr)
        ->toContain("\x1b[30;107m")
        ->toContain("\x1b[0m")
        ->toContain('█')
        ->toContain('▀')
        ->toContain('▄');

    $plainQr = HalfBlockQrOutput::forUrl('https://feat-x.203-0-113-10.sslip.io', ansi: false);
    $lines = explode("\n", $plainQr);

    // Strip the 2-space left margin; first line is the 2-module top quiet zone (all spaces)
    $firstRow = substr($lines[0], 2);
    expect($plainQr)->not->toContain("\x1b[")
        ->and(trim($firstRow, ' '))->toBe('')
        ->and(count($lines))->toBeGreaterThan(10)
        ->and(count($lines))->toBeLessThan(25);

    // Verify direct QROptions invocation also works
    $direct = (new QRCode(new QROptions([
        'outputType' => QROutputInterface::CUSTOM,
        'outputInterface' => HalfBlockQrOutput::class,
        'quietzoneSize' => 2,
    ])))->render('https://novislab.dev');

    expect($direct)->toContain('█');
});
