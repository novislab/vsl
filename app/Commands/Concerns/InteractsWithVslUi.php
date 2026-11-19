<?php

namespace App\Commands\Concerns;

use Closure;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Output\NullOutput;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;

trait InteractsWithVslUi
{
    protected int $startNs = 0;

    protected function startTimer(): void
    {
        $this->startNs = hrtime(true);
    }

    /**
     * Format elapsed time as e.g. "284ms" (< 1000ms) or "1.24s" (>= 1000ms).
     */
    protected function elapsedFormatted(): string
    {
        return self::formatElapsedNs(hrtime(true) - $this->startNs);
    }

    public static function formatElapsedNs(int|float $elapsedNs): string
    {
        $elapsedMs = max(0.0, $elapsedNs / 1_000_000);

        if ($elapsedMs < 1000) {
            return sprintf('%dms', (int) round($elapsedMs));
        }

        return sprintf('%.2fs', $elapsedMs / 1000);
    }

    /**
     * Configure Laravel Prompts output and non-TTY / hook safety.
     */
    protected function setupPromptsUi(bool $json = false): void
    {
        Prompt::setOutput($json ? new NullOutput : $this->output);
        Prompt::interactive(! $json && $this->isInteractiveTty());
    }

    protected function isInteractiveTty(): bool
    {
        if (! $this->input->isInteractive()) {
            return false;
        }

        return stream_isatty(STDIN) && stream_isatty(STDOUT);
    }

    /**
     * Run a closure with Laravel Prompts spin() in an interactive TTY,
     * or directly without stty/cursor manipulation in hooks / non-TTY / JSON mode.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function spinStep(string $message, Closure $callback, bool $json = false): mixed
    {
        if ($json) {
            return $callback();
        }

        if (! $this->isInteractiveTty()) {
            info($message);

            return $callback();
        }

        return spin($callback, $message);
    }
}
