#!/usr/bin/env php
<?php

/**
 * File console.php
 *
 * @author    de Cramer Oliver<oliverde8@gmail.com>
 */
require __DIR__.'/../vendor/autoload.php';

$config = \Symfony\Component\Yaml\Yaml::parse(file_get_contents(__DIR__.'/../config.yml'));

$application = new \Symfony\Component\Console\Application('Tinker', '1.0.0');
$application->add(
    new \Tagger\Command\TagCommand(
            $config['github']['token'],
            $config['github']['source_repo'],
            $config['github']['app_repo']
    )
);

$application->run();