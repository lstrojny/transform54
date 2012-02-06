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
    $braces = array();

    foreach ($tokens as $token) {
        //var_dump($token, $arrays, $braces);

        // New array
        if (is_array($token) && $token[0] == T_ARRAY) {
            $arrays++;
            $braces[$arrays] = 0;

        // Ignore typehints
        } elseif (is_array($token) && $arrays > 0 && $braces[$arrays] === 0 && $token[0] == T_VARIABLE) {
            $arrays--;
            $source .= $token[1];
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

$args = $_SERVER['argv'];

if (in_array('--help', $args)) {
    $help = <<<'EOS'
%s: Simplify PHP 5.4 migration

--help              Show this help
--short-arrays      Rewrite array() to []
--static-closures   Add "static" in front of every closure
--dry-run           Only try, don't rewrite anything
--verbose           Be more verbose
--debug             Show extensive debugging information

EOS;
    printf($help, basename($_SERVER['argv'][0]));
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

$files = new \RegexIterator(
    new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir)
    ),
    '/\.php$/'
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
    if (!$dryRun) {
        rename($tempFile, $file->getPathName());
    }
}
