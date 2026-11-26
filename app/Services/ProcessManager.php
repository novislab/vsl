<?php

namespace App\Services;

class ProcessManager
{
    /**
     * Resolve a safe child PGID that is never equal to the caller's own process group.
     */
    public static function resolveChildPgid(int $pid): int
    {
        if ($pid <= 0 || ! function_exists('posix_getpgid')) {
            return $pid;
        }

        $myPid = getmypid() ?: 0;
        $myPgid = $myPid > 0 ? (posix_getpgid($myPid) ?: 0) : 0;
        $childPgid = posix_getpgid($pid);

        if ($childPgid === false || $childPgid <= 0 || $childPgid === $myPgid) {
            return $pid;
        }

        return $childPgid;
    }

    /**
     * Terminate a detached worker process group (SIGTERM with 2s grace, then SIGKILL),
     * ensuring we never signal our own process or process group.
     */
    public function stop(int $pid, int $pgid): void
    {
        if (! function_exists('posix_kill')) {
            return;
        }

        $myPid = getmypid() ?: 0;
        $myPgid = ($myPid > 0 && function_exists('posix_getpgid')) ? (posix_getpgid($myPid) ?: 0) : 0;

        $useGroup = $pgid > 0 && $pgid !== $myPgid && $pgid !== $myPid;
        $target = $useGroup ? -$pgid : $pid;

        if ($target === 0 || $target === $myPid) {
            return;
        }

        if (! @posix_kill($target, 0) && ($pid <= 0 || $pid === $myPid || ! @posix_kill($pid, 0))) {
            return;
        }

        @posix_kill($target, SIGTERM);
        if ($pid > 0 && $pid !== $myPid) {
            @posix_kill($pid, SIGTERM);
        }

        $deadline = hrtime(true) + 2_000_000_000;
        while (hrtime(true) < $deadline) {
            $groupAlive = $useGroup && @posix_kill($target, 0);
            $pidAlive = $pid > 0 && $pid !== $myPid && @posix_kill($pid, 0);

            if (! $groupAlive && ! $pidAlive) {
                return;
            }

            usleep(10_000);
        }

        @posix_kill($target, SIGKILL);
        if ($pid > 0 && $pid !== $myPid) {
            @posix_kill($pid, SIGKILL);
        }
    }

    public function isAlive(int $pid): bool
    {
        if ($pid <= 0 || ! function_exists('posix_kill')) {
            return false;
        }

        return @posix_kill($pid, 0);
    }
}
