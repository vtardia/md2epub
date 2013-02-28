<?php
require_once dirname(__FILE__) . '/../vendor/autoload.php';
use Md2Epub\CLIParser;
use Md2Epub\EBook;
use dflydev\markdown\MarkdownExtraParser;

define('APP_NAME', 'MarkDown to ePub converter');
define('APP_VERSION', '1.0');
define('APP_LICENSE', 'Copyright (c) 2013 Vito Tardia - GPL');

/**
 * Displays program usage
 */
function usage()
{
    printf("usage: %s /source/directory /target/file\n", basename(__FILE__, '.php'));
}

/**
 * Displays program version and copyright
 */
function headline()
{
    printf("%s %s\n%s\n", APP_NAME, APP_VERSION, APP_LICENSE);
}

/**
 * Another simple way to recursively delete a directory that is not empty,
 * edited to return a BOOL value
 * @link http://php.net/manual/en/function.rmdir.php
 */
function rrmdir($dir)
{
    $files = array_diff(scandir($dir), array('.','..'));
    foreach ($files as $file) {
        (is_dir("$dir/$file")) ? rrmdir("$dir/$file") : unlink("$dir/$file");
    }
    return rmdir($dir);
}

/**
 * Markdown Parser functional interface
 */
function markdown($text)
{
    static $parser;
    if (!isset($parser)) {
        $parser = new MarkdownExtraParser();
    }
    return $parser->transform($text);
}

/// MAIN

$shortOptions = 'hv';
$cli = new CLIParser($shortOptions);

$options = $cli->options();
$args = $cli->arguments();

if (isset($options['v'])) {
    headline();
    exit(0);
}

if (isset($options['h'])) {
    headline();
    usage();
    exit(0);
}

if (count($args) < 2) {
    echo "Invalid arguments\n";
    usage();
    exit(1);
}

$srcDir = $args[0];
$targetFile = $args[1];

if (!is_dir($srcDir)) {
    echo "Invalid source directory\n";
    exit(2);
}

if (!is_writable(dirname($targetFile))) {
    echo "Unable to write target file, access denied\n";
    exit(3);
}

try {
    $book = new EBook($srcDir);

    // create temporary destination directory to place HTML files
    $workDir = sys_get_temp_dir() . '/' . $book->id();

    // try to delete if already exists
    if (file_exists($workDir) && is_dir($workDir)) {
        if (!rrmdir($workDir)) {
            throw new \Exception("Unable to remove temporary directory '$workDir'");
        }
    }

    if (!mkdir($workDir)) {
        throw new \Exception("Unable to create temporary directory '$workDir'");
    }

    $book->makeEpub(
        array(
            'out_file'      => $targetFile,
            'working_dir'   => $workDir,
            'templates_dir' => realpath(__DIR__ . '/../share/'),
            'filters'       => array(
                'md' => 'Markdown'
            )
        )
    );

    // clean temporary working directory
    if (!rrmdir($workDir)) {
        throw new \Exception("Unable to remove temporary directory '$workDir'");
    }
} catch (\Exception $e) {
    printf("ERROR: %s\n", $e->getMessage());
    exit($e->getCode());
}

exit(0);
