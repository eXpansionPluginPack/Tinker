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

    /** @var bool  */
    protected $stable = true;

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
        $prepareBranchName = "prepare-$tagName";
        $releaseBranchName = "release-$tagName";

        $originBranch = $this->getOriginBranch($tagName);

        $io->ask('Are you ready?', 'Here we goo!');

        $gitAppRepository = new RepositoryHelper($io, $fs, $this->appRepo);
        $gitSourceRepository = new RepositoryHelper($io, $fs, $this->sourceRepo);
        $pathToSource = $gitSourceRepository->getEditionFolder();

        /**
         * Updating source codes.
         */
        $io->section("Deleting release branch if already exists.");
        try {
            $gitSourceRepository->deleteBranch($releaseBranchName);
        } catch (ProcessFailedException $exception) {
            $io->note("Sources - Branch $releaseBranchName didn't exist.");
        }
        try {
            $gitSourceRepository->deleteBranch($prepareBranchName);
        } catch (ProcessFailedException $exception) {
            $io->note("Sources - Branch $prepareBranchName didn't exist.");
        }
        try {
            $gitAppRepository->deleteBranch($releaseBranchName);
        } catch (ProcessFailedException $exception) {
            $io->note("App - Branch $releaseBranchName didn't exist.");
        }

        $io->section('Sources - Updating change logs');
        $gitSourceRepository->createBranch($originBranch, $prepareBranchName);
        $changeLog = $this->updateChangeLogs($gitSourceRepository, $tagName);
        $gitSourceRepository->commit("Update changelogs for : $tagName");
        $gitSourceRepository->push($prepareBranchName);


        $io->section('Sources - Updating version in source codes');
        $gitSourceRepository->createBranch($prepareBranchName, $releaseBranchName);
        $this->findAndUpdateVersion($gitSourceRepository, $pathToSource, $tagName);
        $gitSourceRepository->commit("Bump version $tagName");
        $gitSourceRepository->push($releaseBranchName);


        if (!$io->confirm("Branch $releaseBranchName was pushed, is all ok?")) {
            return;
        }
        $gitSourceRepository->tagRelease($releaseBranchName, $tagName);

        $release = $client->api('repo')->releases()->create(
            $gitSourceRepository->getOwner(),
            $gitSourceRepository->getRepository(),
            [
                'tag_name' => $tagName,
                'name' => "v$tagName",
                "body" => "$changeLog",
                'prerelease' => $input->getOption('prerelease'),
            ]
        );

        /*
         *Install latest tag.
         */
        $io->section('App - Composer - Updating Composer json to install latest version.');
        $gitAppRepository->createBranch('master', $releaseBranchName);

        $composerOriginalContent = file_get_contents($gitAppRepository->getEditionFolder(). '/composer.json');
        $composer = json_decode($composerOriginalContent);
        $composer->require->{"expansion-mp/expansion"} = $tagName;
        file_put_contents($gitAppRepository->getEditionFolder(). '/composer.json', json_encode($composer, JSON_PRETTY_PRINT));

        $io->confirm("Will run 'composer update' be sure packatges are up to date in packagist.", "It's ok, rock and roll!");

        $io->section('App - Composer - Running composer update.');
        ProcessRunner::runCommand(
            sprintf(
                'cd %s && composer update --prefer-dist --no-scripts --no-suggest --ignore-platform-reqs',
                $gitAppRepository->getEditionFolder()
            ),
            600
        );

        $io->section('App - Composer - Updating Composer json for generic tag usage.');
        $composer->require->{"expansion-mp/expansion"} = $this->getGenericTag($io, $tagName);
        $composerContent = json_encode($composer, JSON_PRETTY_PRINT);
        file_put_contents($gitAppRepository->getEditionFolder(). '/composer.json', $composerContent);

        if ($this->stable && $composerOriginalContent != $composerContent) {
            $gitAppRepository->add('composer.json');
            $appNeedsUpDate = true;
        }

        $appNeedsUpDate = false;

        $io->section('App - Updating base config files.');
        $appNeedsUpDate = $this->updateConfigFiles($io, $gitSourceRepository, $gitAppRepository) || $appNeedsUpDate;
        $appNeedsUpDate = $this->updateConfigFiles($io, $gitSourceRepository, $gitAppRepository) || $appNeedsUpDate;
        if ($appNeedsUpDate) {
            $io->section("App - Pushing changes to : $tagName");

            $gitAppRepository->commit("Bump for version $tagName");
            $gitAppRepository->push($releaseBranchName);
        }

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

    public function getOriginBranch($tagName) {
        $tagPieces = explode('.', $tagName);

        return "$tagPieces[0].$tagPieces[1].$tagPieces[2].x";
    }

    protected function updateChangeLogs(RepositoryHelper $repo, $tag)
    {
        $tagPieces = explode('.', $tag);
        $file = "CHANGELOG-$tagPieces[0].$tagPieces[1].$tagPieces[2].md";
        $newChangeLogs = '';
        $chnageLog = '';
        $start = false;

        $handle = fopen($repo->getEditionFolder() . "/$file", "r");
        var_dump("# $tagPieces[0].$tagPieces[1].$tagPieces[2].x");
        while (($line = fgets($handle)) !== false) {
            var_dump(strpos($line,"# $tagPieces[0].$tagPieces[1].$tagPieces[2].x"));
            if (strpos($line,"# $tagPieces[0].$tagPieces[1].$tagPieces[2].x") === 0) {
                $start = true;
                $date = date('Y-m-d');
                $newChangeLogs .= "# $tag ($date)";
            } else {
                $newChangeLogs .= "$line\n";

                if (strpos($line,'# ') === 0) {
                    $start = false;
                }
            }

            if ($start) {
                $chnageLog .= "$line";
            }
        }

        fclose($handle);
        file_put_contents($repo->getEditionFolder() . "/$file", $newChangeLogs);
        $repo->add($file);

        return $chnageLog;
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

    protected function getGenericTag(SymfonyStyle $io, $tagName)
    {
        $parts = explode(".", $tagName);

        if (count($parts) != 4) {
            if (!$io->ask('Version $tagName is not stable version! Continue ?')) {
                exit;
            }
            $this->stable = false;
            return $tagName;
        }

        $parts[2] = '*';
        $parts[3] = '*';

        return implode('.', $parts);
    }

    protected function updateConfigFiles(SymfonyStyle $io, RepositoryHelper $sourceRepo, RepositoryHelper $appRepo)
    {
        $files = [
            'app/config',
            'app/AppKernel.php',
        ];
        $updated = false;

        foreach ($files as $path) {
            $fpath = $sourceRepo->getEditionFolder() . '/' . $path;
            if (is_dir($fpath)) {
                foreach (scandir($fpath) as $dirPath) {
                    $updated = $this->updateConfigFile($io, $sourceRepo, $appRepo, $path . '/' . $dirPath) || $updated;
                }
            } else {
                $updated = $this->updateConfigFile($io, $sourceRepo, $appRepo, $path) || $updated;
            }
        }

        return $updated;
    }

    protected function updateConfigFile(SymfonyStyle $io, RepositoryHelper $sourceRepo, RepositoryHelper $appRepo, $path)
    {
        if (is_dir($sourceRepo->getEditionFolder() . '/' . $path)) {
            return false;
        }

        $sourceFile = file_get_contents($sourceRepo->getEditionFolder() . '/' . $path);
        $appFile = file_get_contents($appRepo->getEditionFolder() . '/' . $path);

        if ($sourceFile != $appFile) {
            $io->note("Updated file $path");

            file_put_contents($appRepo->getEditionFolder() . '/' . $path, $sourceFile);
            $appRepo->add($path);

            return true;
        } else {
            $io->note("no changes ignoring file $path");
        }
    }
}
