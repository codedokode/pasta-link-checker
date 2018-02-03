<?php 

namespace Checker;
use Doctrine\Common\Cache\FilesystemCache;
use Fetcher;
use GuzzleHttp\Client;
use LinkChecker;
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
$startUrl = 'https://github.com/codedokode/pasta/blob/master/README.md';
$urlTemplate = 'https://github.com/codedokode/pasta/blob/master/';
$defaultCacertPath =  CacertBundle::getFilePath();
// $certificatesFile = __DIR__ . '/cacert-march-2016.pem';
$userAgent = "codedokode-link-checker-bot (+https://github.com/codedokode/pasta-link-checker)";

$output = new ConsoleOutput(OutputInterface::VERBOSITY_DEBUG);
$logger = new ConsoleLogger($output);

$inputDefinition = new InputDefinition([
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

// foreach (array_slice($argv, 1) as $arg) {
//     if ($arg == '--clear-cache') {
//         $clearCache = true;
//     } else {
//         throw new \Exception("Invalid argument $arg");
//     }
// }


$client = new Client([
    'headers' =>   [
        'User-Agent' => $userAgent
    ]
]);
$client->setDefaultOption('verify', $cacertPath);

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

function printHelp(InputDefinition $def, OutputInterface $output)
{
    $output->writeln(sprintf("%s - checks URLS is documents", basename(__FILE__)));
    $output->writeln("");

    $descriptor = new TextDescriptor();
    $descriptor->describe($output, $def);

    $output->writeln("");
    // echo $def->getSynopsis(false);
    // echo "\n";
}

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

// function canUseState($stateFile, $maxAge)
// {
//     if (!file_exists($stateFile)) {
//         return false;
//     }

//     $mtime = filemtime($stateFile);
//     if (time() - $mtime > $maxAge) {
//         return false;
//     }

//     return true;
// }

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
