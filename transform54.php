<?php
namespace LS;

function lint($source, $lastTransformer)
{
    $file = tempnam(sys_get_temp_dir(), 'php54');
    file_put_contents($file, $source);
    $cmd = $_SERVER['_'] . ' -n -l ' .  escapeshellarg($file) . ' > /dev/null';
    passthru($cmd, $returnCode);
    if ($returnCode != 0) {
        printf("After applying %s, result no longer lints. Please check %s\n", $lastTransformer, $file);
        exit(11);
    }
    return $file;
}

function transform_long_arrays(array $tokens)
{
    $source = '';

    $arrays = 0;
    $braces = [];

    foreach ($tokens as $ptr => $token) {
        // New array
        if (is_array($token) && $token[0] == T_ARRAY) {
            $isTypehint = true;
            $ptr++;
            while (true) {
                if (is_array($tokens[$ptr]) && $tokens[$ptr][0] == T_WHITESPACE) {
                    $ptr++;
                } elseif (is_string($tokens[$ptr]) && $tokens[$ptr] == '(') {
                    $isTypehint = false;
                    break;
                } else {
                    break;
                }
            }

            if (!$isTypehint) {
                $arrays++;
                $braces[$arrays] = 0;
            } else {
                $source .= $token[1];
            }

        } elseif (is_string($token)) {

            // Opening brace, replace
            if ($arrays > 0 && $braces[$arrays] === 0 && $token == '(') {
                $source .= '[';
                $braces[$arrays]++;

            // Some other opening brace, just count
            } elseif ($arrays > 0 && $token == '(') {
                $braces[$arrays]++;
                $source .= $token;

            // Closing brace, decrement
            } elseif ($arrays > 0 && $token == ')' && $braces[$arrays] > 1) {
                $braces[$arrays]--;
                $source .= $token;

            // Closing brace of the array, replace
            } elseif ($arrays > 0 && $token == ')' && $braces[$arrays] == 1) {
                $source .= ']';
                $arrays--;
            } else {
                $source .= $token;
            }
        } else {
            $source .= $token[1];
        }
    }

    return $source;
}

function transform_closures($tokens)
{
    $functions = 0;
    $braces = [];

    $source = '';

    foreach ($tokens as $p => $token) {
        if (is_array($token) && $token[0] == T_FUNCTION) {
            $isClosure = false;
            $ptr = $p + 1;
            while (true) {
                if (is_array($tokens[$ptr]) && $tokens[$ptr][0] == T_WHITESPACE) {
                    $ptr++;
                } elseif (is_string($tokens[$ptr]) && $tokens[$ptr] == '(') {
                    $isClosure = true;
                    break;
                } else {
                    break;
                }
            }
            $isStatic = false;
            $ptr = $p - 1;
            while (true) {
                if (is_array($tokens[$ptr]) && $tokens[$ptr][0] == T_WHITESPACE) {
                    $ptr--;
                } elseif (is_array($tokens[$ptr]) && $tokens[$ptr][0] == T_STATIC) {
                    $isStatic = true;
                    break;
                } else {
                    break;
                }
            }
            if ($isClosure && !$isStatic) {
                $source .= str_replace('function', 'static function', $token[1]);
            } else {
                $source .= $token[1];
            }
        } elseif (is_array($token)) {
            $source .= $token[1];
        } else {
            $source .= $token;
        }
    }

    return $source;
}

$args = $_SERVER['argv'];

if (in_array('--help', $args) || count($args) == 1) {
    $help = <<<'EOS'
%1$s: Simplify PHP 5.4 migration

Usage:
%1$s [options] <directory>

Options:
--help              Show this help
--short-arrays      Rewrite array() to []
--static-closures   Add "static" in front of every closure
--dry-run           Only try, don't rewrite anything
--verbose           Be more verbose
--debug             Show extensive debugging information
--extensions        File extensions to look for (comma-separated). Default: php

EOS;
    printf($help, basename($args[0]));
    exit(1);
}

$dir = array_pop($args);
if (!is_dir($dir)) {
    printf("%s is not a directory\n", $dir);
    exit(1);
}

$debug = in_array('--debug', $args);
$verbose = $debug || in_array('--verbose', $args);
$dryRun = in_array('--dry-run', $args);

$transformers = [];
if (in_array('--short-arrays', $args)) {
    $transformers[] = 'LS\transform_long_arrays';
}

if (in_array('--static-closures', $args)) {
    $transformers[] = 'LS\transform_closures';
}

if (empty($transformers)) {
    printf("%s: No transformers specified\n", basename($args[0]));
    exit(11);
}

$regex = '/\.php$/';
if (($pos = array_search('--extensions', $args)) !== false && isset($args[$pos+1])) {
    $extensions = explode(',', $args[$pos+1]);
    $extensions = array_map('preg_quote', $extensions);
    $regex = '/\.' . join($extensions, '|') . '$/';
}

$files = new \RegexIterator(
    new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir)
    ),
    $regex
);

foreach ($files as $file) {
    $tempFile = lint(file_get_contents($file->getPathName()), 'initiial reading');
    foreach ($transformers as $transformer) {
        if ($verbose) {
            printf("Applying transformer \"%s\" to \"%s\"\n", $transformer, $file->getPathName());
        }
        $source = file_get_contents($tempFile);
        if ($debug) {
            echo $source . "\n";
        }
        $source = $transformer(token_get_all($source));
        if ($debug) {
            echo $source . "\n";
        }
        $tempFile = lint($source, $transformer);
    }
    if ($dryRun && $verbose) {
        printf("Result file %s\n", $tempFile);
    }
    if (!$dryRun) {
        rename($tempFile, $file->getPathName());
    }
}
