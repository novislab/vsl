<?php

namespace App\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputAbstract;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Renders a QR matrix to compact terminal rows by packing two vertical
 * modules per character cell using Unicode half-block glyphs (`▀`, `▄`, `█`, ` `)
 * with a 2-module quiet zone and high-contrast ANSI styling.
 */
class HalfBlockQrOutput extends QROutputAbstract
{
    public const MIME_TYPE = 'text/plain';

    private const ANSI_BLACK_ON_BRIGHT_WHITE = "\x1b[30;107m";

    private const ANSI_RESET = "\x1b[0m";

    public const NO_ANSI_MARKER = '__NO_ANSI__';

    /**
     * Render a QR code string for the given payload/URL.
     */
    public static function forUrl(string $url, bool $ansi = true): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::CUSTOM,
            'outputInterface' => self::class,
            'eccLevel' => EccLevel::L,
            'addQuietzone' => true,
            'quietzoneSize' => 2,
            'eol' => "\n",
            'textLineStart' => $ansi ? self::ANSI_BLACK_ON_BRIGHT_WHITE : self::NO_ANSI_MARKER,
        ]);

        return (new QRCode($options))->render($url);
    }

    public static function moduleValueIsValid($value): bool
    {
        return is_string($value);
    }

    protected function prepareModuleValue($value): string
    {
        return (string) $value;
    }

    protected function getDefaultModuleValue(bool $isDark): string
    {
        return $isDark ? '█' : ' ';
    }

    /**
     * Pack pairs of vertical QR matrix rows into Unicode half-block lines.
     */
    public function dump(?string $file = null): string
    {
        $size = $this->matrix->getSize();
        $quiet = $this->options->addQuietzone ? 0 : 2;
        $rawLineStart = $this->options->textLineStart;
        $lineStart = match ($rawLineStart) {
            self::NO_ANSI_MARKER => '',
            '' => self::ANSI_BLACK_ON_BRIGHT_WHITE,
            default => $rawLineStart,
        };
        $lineEnd = str_contains($lineStart, "\x1b[") ? self::ANSI_RESET : '';

        $lines = [];

        for ($y = -$quiet; $y < $size + $quiet; $y += 2) {
            $row = '';

            for ($x = -$quiet; $x < $size + $quiet; $x++) {
                $top = $this->isModuleDark($x, $y, $size);
                $bottom = ($y + 1 < $size + $quiet) && $this->isModuleDark($x, $y + 1, $size);

                $row .= match (true) {
                    $top && $bottom => '█',
                    $top && ! $bottom => '▀',
                    ! $top && $bottom => '▄',
                    default => ' ',
                };
            }

            $lines[] = '  '.$lineStart.$row.$lineEnd;
        }

        $data = implode($this->eol, $lines);

        $this->saveToFile($data, $file);

        return $data;
    }

    private function isModuleDark(int $x, int $y, int $size): bool
    {
        if ($x < 0 || $y < 0 || $x >= $size || $y >= $size) {
            return false;
        }

        return $this->matrix->check($x, $y);
    }
}
