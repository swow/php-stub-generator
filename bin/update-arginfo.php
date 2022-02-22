#!/usr/bin/env php
<?php
/**
 * This file is part of Swow
 *
 * @link     https://github.com/swow/swow
 * @contact  twosee <twosee@php.net>
 *
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code
 */

declare(strict_types=1);

foreach ([0, 2] as $level) {
    foreach ([dirname(__DIR__, 1 + $level) . '/vendor/autoload.php', dirname(__DIR__, 3 + $level) . '/autoload.php'] as $file) {
        if (file_exists($file)) {
            require $file;
            break;
        }
    }
}

use Swow\Util\FileSystem;
use function Swow\Util\error;
use function Swow\Util\httpDownload;
use function Swow\Util\info;
use function Swow\Util\notice;
use function Swow\Util\ok;
use function Swow\Util\processExecute;
use function Swow\Util\success;
use function Swow\Util\warn;

$options = getopt('h', ['help', 'clear-cache', 'cache-path::', 'stub-file::'], $restIndex);
$argv = array_slice($argv, $restIndex);

if (isset($options['h']) || isset($options['help']) || count($argv) !== 3) {
    $basename = basename(__FILE__);
    exit(
    <<<TEXT
Usage: php {$basename} \\
         [--clear-cache] [--cache-path=/path/to/cache] [--stub-file=/path/to/ext.stub.php] \\
         <extension-name> <extension-source-path> <extension-build-dir>

TEXT
    );
}

[$extensionName, $cSourcePath, $buildDir] = $argv;

$clearCache = $options['clear-cache'] ?? false;
if ($clearCache) {
    info('Run without cache');
}

$stubCachePath = ($options['cache-path'] ?? sys_get_temp_dir()) . "/stub/{$extensionName}";
if (!is_dir($stubCachePath)) {
    mkdir($stubCachePath, 0755, true);
    if (!is_dir($stubCachePath)) {
        error("Make stub dir failed: {$stubCachePath}");
    }
}
$stubCachePath = realpath($stubCachePath);
info("Stub cache path is {$stubCachePath}");

$cSourceFiles = FileSystem::scanDir($cSourcePath, static function (string $filename) {
    return in_array(pathinfo($filename, PATHINFO_EXTENSION), ['c', 'cc', 'cpp'], true);
});
$functionCount = $methodCount = $replacedCount = 0;
foreach ($cSourceFiles as $cSourceFile) {
    $cSource = file_get_contents($cSourceFile);
    $functionNames = $classNames = $methodNames = [];
    if (preg_match_all('/(?:ZNED|PHP)_FUNCTION\(([^)]+)\)/', $cSource, $matches)) {
        $functionNames = $matches[1];
        $functionCount += count($functionNames);
    }
    if (preg_match_all('/(?:ZNED|PHP)_ME\(([^,]+,[ ]*[^,]+),[ ]*(arginfo_[^,]+)/', $cSource, $matches)) {
        $classNames = array_unique(array_map(static function (string $name) {
            return array_map('trim', explode(',', $name))[0];
        }, $matches[1]));
        $methodNames = array_map(static function (string $name) {
            return implode('_', array_map('trim', explode(',', $name)));
        }, $matches[1]);
        $argInfoNames = $matches[2];
        $methodCount += count($methodNames);
        $nonStandardArgInfoNameMap = [];
        foreach ($argInfoNames as $index => $argInfoName) {
            $standardArgInfoName = "arginfo_class_{$methodNames[$index]}";
            if ($argInfoName !== $standardArgInfoName) {
                warn("Arginfo '{$argInfoName}' is not standard, it should be '{$standardArgInfoName}'");
                $nonStandardArgInfoNameMap[$standardArgInfoName] = true;
            }
        }
    }

    if ($functionNames || $methodNames) {
        info("Start updating arginfo for {$cSourceFile}");
    } else {
        info("There is no arginfo in {$cSourceFile}");
        continue;
    }

    $stubNameForModule = preg_replace('/\.' . pathinfo($cSourceFile, PATHINFO_EXTENSION) . '$/', '', basename($cSourceFile));
    $stubFilePathForModule = "{$stubCachePath}/{$stubNameForModule}.stub.php";

    if ($clearCache || !file_exists($stubFilePathForModule)) {
        $functionAndMethodNames = [...$functionNames, ...$methodNames];
        $functionAndMethodNameFilter = implode('|', $functionAndMethodNames);
        $stubSourceOfModule = processExecute([
            PHP_BINARY,
            "-d extension={$extensionName}",
            __DIR__ . '/gen-stub.php',
            '--gen-arginfo-mode',
            '--function-filter=' . implode('|', $functionNames),
            '--class-filter=' . implode('|', $classNames),
            ...(isset($options['stub-file']) ? ["--stub-file={$options['stub-file']}"] : []),
            $extensionName,
        ]);
        if (!$stubSourceOfModule) {
            notice("No stub info generated by {$cSourceFile}");
            continue;
        }
        file_put_contents($stubFilePathForModule, $stubSourceOfModule);
        info("Put stub file to {$stubFilePathForModule}");
    }

    /* Use php-src gen_stub.php to generate arginfo.h */
    $genStubXPath = "{$buildDir}/gen_stub_x.php";
    if (!file_exists($genStubXPath)) {
        $genStubPath = "{$buildDir}/gen_stub.php";
        if (!file_exists($genStubPath)) {
            if (!is_dir($buildDir) && !@mkdir($buildDir, 0755)) {
                error(sprintf('Failed to create dir for build scripts (%s)', error_get_last()['message']));
            }
            try {
                httpDownload('https://raw.githubusercontent.com/php/php-src/master/build/gen_stub.php', $genStubPath);
            } catch (RuntimeException $exception) {
                error($exception->getMessage());
            }
        }
        $genStubSource = file_get_contents($genStubPath);
        $genStubSourceReplaceMap = [
            '/throw new Exception\("Not implemented {\$classStmt->getType\(\)}"\);/' => 'if (!($classStmt instanceof Stmt\ClassConst)) { $0 }',
            '/error_reporting\(E_ALL\);/' => 'error_reporting(E_ALL ^ E_DEPRECATED);',
            '/"\|ZEND_ACC_/' => '" | ZEND_ACC_',
        ];
        $genStubXSource = preg_replace(array_keys($genStubSourceReplaceMap), array_values($genStubSourceReplaceMap), $genStubSource);
        file_put_contents($genStubXPath, $genStubXSource);
    }
    $output = processExecute([PHP_BINARY, $genStubXPath, $stubFilePathForModule], $status);
    $argInfoFilePathForModule = "{$stubCachePath}/{$stubNameForModule}_arginfo.h";
    if (!file_exists($argInfoFilePathForModule) || $status['exitcode'] !== 0) {
        $output = str_replace("\n", ' ', $output);
        warn("Generate arginfo header file for {$stubNameForModule} failed with exit code {$status['exitcode']} and output: {$output}");
        continue;
    }
    $argInfoSourceForModule = str_replace("\t", '    ', file_get_contents($argInfoFilePathForModule));

    $replacedCountT = 0;
    $argInfoNames = [
        ...array_map(static function (string $functionName) {
            return "arginfo_{$functionName}";
        }, $functionNames),
        ...array_map(static function (string $methodName) {
            return "arginfo_class_{$methodName}";
        }, $methodNames),
    ];
    foreach ($argInfoNames as $argInfoName) {
        $argInfoBodyRegex = "/ZEND_BEGIN_ARG[^(]+\\({$argInfoName},[\\s\\S]+?ZEND_END_ARG[^)]+\\)/";
        $argInfoDefineRegex = "/#define {$argInfoName} [^\n]+/";
        if (preg_match($argInfoBodyRegex, $cSource, $matches) ||
            preg_match($argInfoDefineRegex, $cSource, $matches)) {
            $oldArgInfoBody = $matches[0];
        } else {
            if (!isset($nonStandardArgInfoNameMap[$argInfoName])) {
                warn("Unable to find target function/method of '{$argInfoName}'");
            }
            continue;
        }
        if (!preg_match($argInfoBodyRegex, $argInfoSourceForModule, $matches) &&
            !preg_match($argInfoDefineRegex, $argInfoSourceForModule, $matches)) {
            warn("Unable to find '{$argInfoName}' in new arginfo source");
            continue;
        }
        $newArgInfoBody = $matches[0];
        // -Wunicode: \U used with no following hex digits; treating as '\' followed by identifier
        $newArgInfoBody = preg_replace('/\\\\\\\\([uUxX])/', '\x5c$1', $newArgInfoBody);
        if ($oldArgInfoBody === $newArgInfoBody) {
            continue;
        }
        $cSource = str_replace($oldArgInfoBody, $newArgInfoBody, $cSource, $replacedCountTT);
        $replacedCountT += $replacedCountTT;
    }

    if (!file_put_contents($cSourceFile, $cSource)) {
        warn("Unable to update source file for {$cSourceFile}");
    } else {
        ok("Arginfo updated with {$replacedCountT} changes for {$cSourceFile}");
    }
    $replacedCount += $replacedCountT;
}

success("Done with {$functionCount} functions and {$methodCount} methods, {$replacedCount} replaced");
