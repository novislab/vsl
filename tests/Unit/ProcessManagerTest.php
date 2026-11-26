<?php

use App\Services\ProcessManager;

it('never kills the caller process or process group in stop()', function () {
    $manager = new ProcessManager;
    $myPid = getmypid();
    $myPgid = posix_getpgid($myPid);

    $manager->stop($myPid, $myPgid);
    expect($manager->isAlive($myPid))->toBeTrue();
});
