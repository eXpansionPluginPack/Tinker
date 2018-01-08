<?php

namespace Tagger\Helper;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Class ProcessRunner
 *
 * @author    de Cramer Oliver
 * @package Tagger\Helper
 */
class ProcessRunner
{
    /**
     * Run a terminal command
     *
     * @param string  $command
     * @param integer $timeout
     *
     * @return string
     */
    public static function runCommand($command, $timeout = 60)
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
        return $process->getOutput();
    }
}