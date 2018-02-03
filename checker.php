<?php 

namespace UrlChecker;

use Doctrine\Common\Cache\FilesystemCache;
use GuzzleHttp\Client;
use GuzzleHttp\Url;
use PhpExtended\RootCacert\CacertBundle;
use Symfony\Component\Console\Descriptor\TextDescriptor;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\Input;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

require_once __DIR__ . '/vendor/autoload.php';
initExceptions();

$cacheDir = __DIR__ . '/cache/';
$cacheLifetimeSeconds = 900;

$clearCache = false;
$defaultStartUrl = 'https://github.com/codedokode/pasta/blob/master/README.md';
// $urlTemplate = 'https://github.com/codedokode/pasta/blob/master/';
$defaultCacertPath =  CacertBundle::getFilePath();
$userAgent = "codedokode-link-checker-bot (+https://github.com/codedokode/pasta-link-checker)";

$output = new ConsoleOutput(OutputInterface::VERBOSITY_DEBUG);
$logger = new ConsoleLogger($output);

$inputDefinition = new InputDefinition([
    new InputOption(
        'url',
        'u',
        InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
        'Starting URLs',
        [$defaultStartUrl]
    ),
    new InputOption(
        'follow',
        'f',
        InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
        'Follow links inside this area, if not given the equals the URLS',
        []
    ),
    new InputOption(
        'clear-cache', 
        null, 
        InputOption::VALUE_NONE, 
        'Clear cache before running the checker'
    ),
    new InputOption(
        'cacert-path', 
        null, 
        InputOption::VALUE_REQUIRED, 
        'Specify cacert path for validating certificates', 
        $defaultCacertPath
    ),
    new InputOption(
        'help', 
        'h', 
        InputOption::VALUE_NONE, 
        'Print help message'
    )
]);

$input = new ArgvInput();

try {
    $input->bind($inputDefinition);
    $input->validate();
} catch (RuntimeException $ex) {
    fprintf(STDERR, "Invalid usage: %s\n\n", $ex->getMessage());
    printHelp($inputDefinition, $output);
    exit(1);
}

if ($input->getOption('help')) {
    printHelp($inputDefinition, $output);
    exit(0);
}

$cacertPath = $input->getOption("cacert-path");
$clearCache = $input->getOption("clear-cache");
$startUrls = $input->getOption('url');
$followUrls = $input->getOption('follow');

if (!$followUrls) {
    $followUrls = deduceFollowUrls($startUrls);
}

foreach ($followUrls as $followUrl) {
    $logger->debug("- Recurse into area: $followUrl");
}

if (!$startUrls) {
    throw new \Exception("Need at least one start URL");
}

$client = new Client([
    'headers' =>   [
        'User-Agent' => $userAgent
    ]
]);
$client->setDefaultOption('verify', $cacertPath);

$fileCache = new FilesystemCache($cacheDir);
$fetcher = new Fetcher($client, $fileCache, $fileCache, $cacheLifetimeSeconds, $logger);

$linkChecker = new LinkChecker($fetcher, $logger);
if ($clearCache) {
    $fileCache->deleteAll();
}

$urlQueue = new UrlQueue;
foreach ($startUrls as $startUrl) {
    $urlQueue->addIfNew($startUrl);    
}

while ($urlQueue->getQueuedCount() > 0) {
    $url = pickBestUrl($fetcher, $urlQueue);
    $urlQueue->markChecked($url);

    processUrl($linkChecker, $urlQueue, $logger, $url, $followUrls);
}

$failures = $fetcher->getFailedUrlList();
if ($failures) {
    echo "\nFailed URLs: \n";
    foreach ($failures as $url => $reason) {
        echo "$url: $reason\n";
    }
}

$code = count($failures) ? 1 : 0;
exit($code);

function printHelp(InputDefinition $def, OutputInterface $output)
{
    $output->writeln(sprintf("%s - checks URLS is documents", basename(__FILE__)));
    $output->writeln("");

    $descriptor = new TextDescriptor();
    $descriptor->describe($output, $def);

    $output->writeln("");
}

function processUrl(LinkChecker $linkChecker, UrlQueue $urlQueue, $logger, $url, array $followUrlsInside)
{
    list($mustSkip, $skipReason) = mustSkipUrl($url);
    if ($mustSkip) {
        $logger->info("Skip $url: $skipReason");
        return;
    }

    $shouldCollectLinks = shouldCollectLinks($url, $followUrlsInside);
    $hash = parse_url($url, PHP_URL_FRAGMENT);

    $preloadBody = $shouldCollectLinks || !empty($hash);

    $metadata = $linkChecker->checkUrl($url, $preloadBody);

    if (!$metadata->isSuccessful()) {
        $logger->error("- $url [{$metadata->getErrorReason()}]");
        return;
    } else {
        $logger->info("- $url [ok]");
    }

    if ($shouldCollectLinks) {
        $newLinks = $linkChecker->collectUrlsFromPage($url);

        $unseen = 0;
        foreach ($newLinks as $newLink) {
            $isNew = $urlQueue->addIfNew($newLink);
            $unseen += ($isNew ? 1 : 0);
        }

        $logger->info(sprintf("found %d links, %d are new", count($newLinks), $unseen));
    }
}

function pickBestUrl(Fetcher $fetcher, UrlQueue $urlQueue)
{
    $queuedUrls = $urlQueue->getQueuedUrls();
    $tries = 50; // Prevent using too much time
    $bestTime = INF;

    reset($queuedUrls);
    $bestUrl = key($queuedUrls);

    foreach ($queuedUrls as $url => $dummy) {
        $time = $fetcher->getExpectedFetchTime($url);
        if ($time == 0) {
            // No need to look further
            return $url;
        }

        if ($time < $bestTime) {
            $bestTime = $time;
            $bestUrl = $url;
        }

        $tries--;

        if ($tries <= 0) {
            break;
        }
    }

    return $bestUrl;
}

/**
 * @return [bool $canSkip, string $reason]
 */
function mustSkipUrl($url)
{
    // Ignore example domains
    $host = parse_url($url, PHP_URL_HOST);
    if (preg_match("/\.local$/ui", $host) || preg_match("/example\.\w+$/ui", $host)) {        
        return [true, "is an example domain"];
    }

    return [false, null];
}

function shouldCollectLinks($url, array $followUrlsBase)
{
    foreach ($followUrlsBase as $base) {
        if (startsWith($url, $base)) {
            return true;
        }
    }

    return false;
}

function deduceFollowUrls(array $startUrls)
{
    $followUrls = [];

    foreach ($startUrls as $url) {
        $followUrls[] = deduceFollowUrlFromStartUrl($url);
    }

    return $followUrls;
}

/**
 * http://example.com/some/domain.txt -> http://example.com/some/
 */
function deduceFollowUrlFromStartUrl($url)
{
    $urlObject = Url::fromString($url);
    $urlObject->setFragment(null);
    $urlObject->setQuery([]);

    $path = $urlObject->getPath();
    if (mb_strlen($path) > 1) {
        // Remove last segment
        $path = preg_replace('~\/[^/]+$~', '/', $path);
        $urlObject->setPath($path);
    }

    return $urlObject->__toString();
}

function initExceptions()
{
    set_error_handler(
        function ($errno, $errstr, $errfile, $errline ) {
            if (!(error_reporting() & $errno)) {
                return;
            }
            throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        }
    );
}

function startsWith($url, $urlStart)
{
    return strpos($url, $urlStart) === 0;
}
