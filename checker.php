<?php 

namespace Checker;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Fetcher;
use LinkChecker;
use GuzzleHttp\Client;
use Doctrine\Common\Cache\FilesystemCache;

require_once __DIR__ . '/vendor/autoload.php';
initExceptions();

$cacheDir = __DIR__ . '/cache/';
$cacheLifetimeSeconds = 900;

// $maxStateAge = 1800;
$clearCache = false;
$startUrl = 'https://github.com/codedokode/pasta/blob/master/README.md';
$urlTemplate = 'https://github.com/codedokode/pasta/blob/master/';
$certificatesFile = __DIR__ . '/cacert-march-2016.pem';
$userAgent = "codedokode-link-checker-bot (+https://github.com/codedokode/pasta-link-checker)";

foreach (array_slice($argv, 1) as $arg) {
    if ($arg == '--clear-cache') {
        $clearCache = true;
    } else {
        throw new \Exception("Invalid argument $arg");
    }
}


$output = new ConsoleOutput(OutputInterface::VERBOSITY_DEBUG);
$logger = new ConsoleLogger($output);
$client = new Client([
    'headers' =>   [
        'User-Agent' => $userAgent
    ]
]);
$client->setDefaultOption('verify', $certificatesFile);

$fileCache = new FilesystemCache($cacheDir);
$fetcher = new Fetcher($client, $fileCache, $fileCache, $cacheLifetimeSeconds, $logger);

$saveCounter = 0;
// $fetcher->addAfterFetchHandler(function () use (&$saveCounter, $fetcher, $stateFile) {
//     $saveCounter ++;
//     if ($saveCounter % 5 == 1) {
//         saveFetcherState($fetcher, $stateFile);
//     }
// });

$linkChecker = new LinkChecker($fetcher, $logger);
if ($clearCache) {
    $fileCache->deleteAll();
}

// if (!$ignoreState && canUseState($stateFile, $maxStateAge)) {
//     loadFetcherState($fetcher, $stateFile);
// }

$checkedUrls = [];
followUrl($linkChecker, $startUrl, $urlTemplate, $checkedUrls);

$failures = $fetcher->getFailedUrlList();
if ($failures) {
    echo "\nFailed URLs: \n";
    foreach ($failures as $url => $reason) {
        echo "$url: $reason\n";
    }
}

$code = count($failures) ? 1 : 0;
exit($code);



function followUrl($linkChecker, $url, $urlTemplate, &$checkedUrls)
{
    global $logger;

    if (isset($checkedUrls[$url])) {
        return;
    }

    // Ignore example domains
    $host = parse_url($url, PHP_URL_HOST);
    if (preg_match("/\.local$/ui", $host) || preg_match("/example\.\w+$/ui", $host)) {
        $logger->info("Ignoring example domain link $url");
        return;
    }

    $checkedUrls[$url] = $url;
    $collected = $linkChecker->collectLinks([$url]);
    $collectedUrls = array_filter(array_keys($collected), function ($url) use ($urlTemplate) {
        
        $path = parse_url($url, PHP_URL_PATH);

        // Check only md files
        if (!preg_match("/\.md$/i", $path)) {
            return false;
        }

        return startsWith($url, $urlTemplate);
    });

    foreach ($collectedUrls as $newUrl) {
        followUrl($linkChecker, $newUrl, $urlTemplate, $checkedUrls);
    }
}

function canUseState($stateFile, $maxAge)
{
    if (!file_exists($stateFile)) {
        return false;
    }

    $mtime = filemtime($stateFile);
    if (time() - $mtime > $maxAge) {
        return false;
    }

    return true;
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

// function saveFetcherState($fetcher, $stateFile)
// {
//     $data = json_encode($fetcher->saveState(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
//     file_put_contents($stateFile, $data, LOCK_EX);
// }

// function loadFetcherState($fetcher, $stateFile)
// {
//     global $logger;

//     $content = file_get_contents($stateFile);
//     $data = json_decode($content, true);
//     if (!$data) {
//         throw new \Exception("Failed to decode JSON from $stateFile");
//     }

//     $logger->info("Loaded " . count($data['urlSuccess']). " saved urls");
//     $fetcher->loadState($data);
// }

function startsWith($url, $urlStart)
{
    return strpos($url, $urlStart) === 0;
}
