<?php 

namespace UrlChecker\Tests;

use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class SmokeTest extends \PHPUnit_Framework_TestCase
{    
    public function testProgramRuns()
    {
        $this->executeLinkChecker(['--help']);
    }
    
    public function testWithSamplePage()
    {
        $url = 'http://127.0.0.1:' . getenv('LINK_CHECKER_TEST_SERVER_PORT') . '/page-with-valid-links.html';
        $this->executeLinkChecker(['--clear-cache', '--url', $url]);
    }
    
    private function executeLinkChecker(array $args)
    {
        $phpFinder = new PhpExecutableFinder();
        $php = $phpFinder->find(false);
        $phpCommand = array_merge([$php], $phpFinder->findArguments());

        $fullCommand = array_merge($phpCommand, ['checker.php'], $args);

        echo "Run command: " . implode(' ', $fullCommand) . "\n";

        $process = new Process($fullCommand);
        $process->disableOutput();
        $process->setTimeout(15);
        $process->mustRun(function ($type, $buffer) {
            if (Process::ERR === $type) {
                echo $buffer;
            } else {
                echo $buffer;
            }
        });
    }
}