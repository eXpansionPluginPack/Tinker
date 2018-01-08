<?php

namespace Tagger\Helper;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Class RepositoryHelper
 *
 * @author    de Cramer Oliver
 * @package Tagger\Helper
 */
class RepositoryHelper
{
    private $io;
    private $fs;

    protected $editionFolder;
    protected $owner;
    protected $repository;

    /**
     * @param SymfonyStyle $io
     * @param Filesystem   $fs
     */
    public function __construct(SymfonyStyle $io, Filesystem $fs, $repository, $mainBranch = 'master')
    {
        $this->io = $io;
        $this->fs = $fs;

        list($owner, $repository) = explode('/', $repository);
        $this->editionFolder = "release/$owner--$repository--$mainBranch";
        $this->owner = $owner;
        $this->repository = $repository;

        $this->cloneRepository($owner, $repository, $mainBranch);
    }

    /**
     * Clone given repository
     *
     * @param string $this->editionFolder
     * @param string $owner
     * @param string $repository
     * @param string $mainBranch
     *
     * @return boolean
     */
    protected function cloneRepository($owner, $repository, $mainBranch)
    {
        try {
            $this->fs->mkdir($this->editionFolder);
        } catch (IOExceptionInterface $e) {
            $this->io->error(sprintf(
                                 'Creation of the release folder: Unable to create the %s folder',
                                 $this->editionFolder
                             ));

            return false;
        }

        $this->io->text(sprintf(
                            'Cloning %s/%s repository (this can take up to 5 minutes depending on your connection)',
                            $owner,
                            $repository
                        ));

        try {
            ProcessRunner::runCommand(
                sprintf(
                    'git clone -b %s --single-branch git@github.com:%s/%s.git %s',
                    $mainBranch,
                    $owner,
                    $repository,
                    $this->editionFolder
                ),
                720
            );
        } catch (ProcessFailedException $e) {
            $this->io->error(sprintf(
                                 'Unable to clone the %s/%s repository with the following error: %s',
                                 $owner,
                                 $repository,
                                 $e->getMessage()
                             ));

            return false;
        }
        $this->io->success(sprintf(
                               'Clone %s/%s repository',
                               $owner,
                               $repository
                           ));

        return true;
    }

    /**
     * Creates a new branch starting from another one.
     *
     * @param string $this->editionFolder
     * @param string $mainBranch
     * @param string $newBranch
     *
     * @return boolean
     */
    public function createBranch($mainBranch, $newBranch)
    {
        try {
            ProcessRunner::runCommand(
                sprintf(
                    'cd %s && git checkout %s && git pull && git checkout -b %s',
                    $this->editionFolder,
                    $mainBranch,
                    $newBranch
                )
            );
        } catch (ProcessFailedException $e) {
            $this->io->error(sprintf(
                                 'Unable to checkout the new branch "%s" with the following error: %s',
                                 $newBranch,
                                 $e->getMessage()
                             ));

            return false;
        }
        $this->io->success(sprintf(
                               'Checkout the branch "%s" (from "%s")',
                               $newBranch,
                               $mainBranch
                           ));

        return true;
    }

    /**
     * Delete the tag branch
     *
     * @param string $this->editionFolder
     * @param string $releaseBranch
     */
    public function deleteBranch($releaseBranch)
    {
        ProcessRunner::runCommand(sprintf('cd %s && git push origin --delete %s', $this->editionFolder, $releaseBranch));
        $this->io->success('I now delete the merged branch');
    }

    /**
     * Add a file.
     *
     * @param string $filePath
     */
    public function add($filePath)
    {
        ProcessRunner::runCommand(sprintf('cd %s && git add %s', $this->editionFolder, $filePath));
        $this->io->success("file added : $filePath");
    }

    /**
     * Commit changes.
     *
     * @param $msg
     */
    public function commit($msg)
    {
        ProcessRunner::runCommand(sprintf('cd %s && git commit -m "%s"', $this->editionFolder, $msg));
        $this->io->success("Commited : $msg");
    }

    public function push($branch)
    {
        ProcessRunner::runCommand(sprintf('cd %s && git push origin %s', $this->editionFolder, $branch));
        $this->io->success("Pushed : origin/$branch");
    }

    /**
     * Tag the branch
     *
     * @param string $this->editionFolder
     * @param string $branch
     *
     * @return boolean
     */
    public function tagRelease($branch, $tagName)
    {
        ProcessRunner::runCommand(sprintf(
                                      'cd %s && git checkout %s && git pull && git tag -a %s -m "" && git push origin %s',
                                      $this->editionFolder,
                                      $branch,
                                      $tagName,
                                      $tagName
                                  ));
        $this->io->success(sprintf(
                               'Tag the %s branch with tag v%s',
                               $branch,
                               $tagName
                           ));

        return true;
    }

    /**
     * @return string
     */
    public function getEditionFolder()
    {
        return $this->editionFolder;
    }

    /**
     * @return mixed
     */
    public function getOwner()
    {
        return $this->owner;
    }

    /**
     * @return mixed
     */
    public function getRepository()
    {
        return $this->repository;
    }



}
