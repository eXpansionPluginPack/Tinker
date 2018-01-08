<?php

namespace Tagger\Command;
use Github\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Iterator\RecursiveDirectoryIterator;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Tagger\Helper\ProcessRunner;
use Tagger\Helper\RepositoryHelper;

/**
 * Class TagCommand
 *
 * @author    de Cramer Oliver
 * @package Tagger\Command
 */
class TagCommand extends Command
{
    /** @var string */
    protected $githubToken;

    /** @var string */
    protected $sourceRepo;

    /** @var string */
    protected $appRepo;

    /**
     * TagCommand constructor.
     *
     * @param string $githubToken
     */
    public function __construct($githubToken, $sourceRepo, $appRepo)
    {
        parent::__construct();

        $this->githubToken = $githubToken;
        $this->sourceRepo = $sourceRepo;
        $this->appRepo = $appRepo;
    }

    /**
     *
     */
    protected function configure()
    {
        parent::configure();

        $this->setName('eXpansion:release:tag');
        $this->setDescription("Release a new version of eXpansion");
        $this->addArgument('tag', InputArgument::REQUIRED, "Name of the new tag");

        $this->addOption("prerelease", null, InputOption::VALUE_NONE, "Should the release be created as a prerelease");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $fs = new Filesystem();
        $fs->remove("release");

        $client = new \Github\Client();
        $client->authenticate($this->githubToken, null, Client::AUTH_HTTP_TOKEN);

        $tagName = $input->getArgument('tag');
        $branchName = "release-$tagName";

        $io->ask('Are you ready?', 'Here we goo!');

        $gitAppRepository = new RepositoryHelper($io, $fs, $this->appRepo);
        $gitSourceRepository = new RepositoryHelper($io, $fs, $this->sourceRepo);
        $pathToSource = $gitSourceRepository->getEditionFolder();

        $io->section("Deleting release branch if already exists.");
        try {
            $gitSourceRepository->deleteBranch($branchName);
        } catch (ProcessFailedException $exception) {
            $io->note("Branch didn't exist.");
        }

        $io->section('Updating version in source codes');
        $gitSourceRepository->createBranch('master', $branchName);
        $this->findAndUpdateVersion($gitSourceRepository, $pathToSource, $tagName);
        $gitSourceRepository->commit("Bump version $tagName");
        $gitSourceRepository->push($branchName);

        if (!$io->confirm("Branch $branchName was pushed, is all ok?")) {
            return;
        }
        $gitSourceRepository->tagRelease($branchName, $tagName);

        $release = $client->api('repo')->releases()->create(
            $gitSourceRepository->getOwner(),
            $gitSourceRepository->getRepository(),
            [
                'tag_name' => $tagName,
                'prerelease' => $input->getOption('prerelease'),
            ]
        );

        $io->section('Install latest tag');
        ProcessRunner::runCommand(
            sprintf(
                'cd %s && composer update --prefer-dist --no-scripts --no-suggest --ignore-platform-reqs',
                $gitAppRepository->getEditionFolder()
            )
        );

        $io->section('Create release zip');
        $zipPath = $this->createZipArchive($gitAppRepository, $tagName);

        $io->section('Uploading release zip to github');

        $client->api('repo')->releases()->assets()->create(
            $gitSourceRepository->getOwner(),
            $gitSourceRepository->getRepository(),
            $release['id'],
            "eXpansion-v$tagName.zip",
            "application/zip",
            file_get_contents($zipPath)
        );
    }


    protected function findAndUpdateVersion(RepositoryHelper $repo, $pathToSource, $tag)
    {
        $coreFile = $pathToSource . '/src/eXpansion/Framework/Core/Services/Application/AbstractApplication.php';

        $content = file_get_contents($coreFile);
        $content = str_replace('const EXPANSION_VERSION = "2.0.0.0"', 'const EXPANSION_VERSION = "' . $tag . '"', $content);
        $content = str_replace('const EXPANSION_VERSION = "dev"', 'const EXPANSION_VERSION = "' . $tag . '"', $content);
        file_put_contents($coreFile, $content);

        $repo->add('src/eXpansion/Framework/Core/Services/Application/AbstractApplication.php');
    }

    protected function createZipArchive(RepositoryHelper $repo, $tagName)
    {
        $zip = new \ZipArchive();
        $zip->open("release/eXpansion-v$tagName.zip", \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $path = $repo->getEditionFolder();

        // Create recursive directory iterator
        /** @var SplFileInfo[] $files */
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $name => $file)
        {
            // Skip directories (they would be added automatically)
            if (!$file->isDir())
            {
                // Get real and relative path for current file
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($path) + 1);

                // Add current file to archive
                $zip->addFile($filePath, $relativePath);
            }
        }

        // Zip archive will be created only after closing object
        $zip->close();

        return "release/eXpansion-v$tagName.zip";
    }
}
