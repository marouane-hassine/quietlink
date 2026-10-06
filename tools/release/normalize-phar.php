<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

/*
 * Rewrites a PHAR with its entries sorted by path, then sets every timestamp and the SHA-512
 * signature as Box does. Box adds files in directory listing order, which differs between
 * filesystems (hash seeds): without this step the same commit gives another SHA-256 on another
 * machine. Usage (from tools/release/build-phar.sh):
 *   php -d phar.readonly=0 normalize-phar.php <in.phar> <out.phar> <timestamp> <path to Seld\PharUtils\Timestamps.php>
 */

declare(strict_types=1);

[, $input, $output, $timestamp, $timestampsClass] = $argv + [null, null, null, null, null];
if (!is_string($input) || !is_string($output) || !is_string($timestamp) || !is_string($timestampsClass)) {
    fwrite(STDERR, "usage: normalize-phar.php <in.phar> <out.phar> <timestamp> <Timestamps.php>\n");
    exit(2);
}
require $timestampsClass;

$source = new Phar($input);
$base = 'phar://' . $source->getPath() . '/';
$stub = $source->getStub();
$entries = [];
foreach (new RecursiveIteratorIterator($source) as $file) {
    assert($file instanceof PharFileInfo);
    $entries[substr($file->getPathname(), strlen($base))] = [(string) file_get_contents($file->getPathname()), $file->getPerms() & 0777];
}
ksort($entries, SORT_STRING);
// Released so that its alias can be given to the rewritten archive.
unset($source, $file);

$temporary = $output . '.unsorted.phar';
@unlink($temporary);
// Same alias as Box writes in the manifest (the stub also maps it at run time).
$phar = new Phar($temporary, 0, 'quietlink.phar');
$phar->startBuffering();
foreach ($entries as $path => [$content]) {
    $phar->addFromString($path, $content);
}
foreach ($entries as $path => [, $perms]) {
    $phar[$path]->chmod($perms);
}
$phar->setStub($stub);
$phar->stopBuffering();
$phar->compressFiles(Phar::GZ);
unset($phar);

$timestamps = new Seld\PharUtils\Timestamps($temporary);
$timestamps->updateTimestamps(new DateTimeImmutable($timestamp));
$timestamps->save($output, Phar::SHA512);
unlink($temporary);
