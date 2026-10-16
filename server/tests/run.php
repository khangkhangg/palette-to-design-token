<?php
// php server/tests/run.php : runs every *-test.php in its own php process.
$bad = 0;
foreach (glob(__DIR__ . '/*-test.php') as $f) {
    echo "== " . basename($f) . "\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($f), $code);
    if ($code !== 0) { $bad++; echo "-- exit $code\n"; }
}
echo $bad ? "$bad test file(s) FAILED\n" : "ALL PASSED\n";
exit($bad ? 1 : 0);
