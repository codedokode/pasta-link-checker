<?php 

namespace UrlChecker\Tests;

use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class SmokeTest extends extends \PHPUnit_Framework_TestCase
{    
    public function testProgramRuns()
    {
        $this->executeScript(['--help']);
    }
    
    public function testWithSamplePage()
    {
        $url = 'http://127.0.0.1:' . getenv('LINK_CHECKER_TEST_SERVER_PORT') . '/start-page.html';
        $this->executeScript(['--url', $url]);
    }
    
    private function executeScript(array $args)
    {
        $phpFinder = new PhpExecutableFinder();
        $php = $phpFinder->find(false);
        $phpCommand = array_merge([$php], $executableFinder->findArguments());

        $fullCommand = array_merge($phpCommand, $args);

        $process = new Process($fullCommand);
        $process->disableOutput();
        $process->setTimeout(15);
        $process->mustRun(function ($type, $buffer) {
            if (Process::ERR === $type) {
                echo 'php: ' . $buffer;
            } else {
                echo 'php: ' . $buffer;
            }
        });
    }
}