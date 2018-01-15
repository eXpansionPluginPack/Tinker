# eXpansion Tinker a way to release eXpansion

This command allows to easily create a new eXpansion release. It will : 
* Update eXpansion source code to update version number.
* Create a new tag 
* Update the skeleton app repository with : 
    * New AppKernel
    * New config files or config dist files.
    * New composer json if necessery.
* Create an archive with the latest sources
* Create a github release with the archive. 

## How to use

1. Prepare release notes
1. Copy `config.yml.dist` to `config.yml`. 
1. Edit the `config.yml` with your github acess token.
1. Lauch the fallowing command : 
    ```bash
    bin/console.php eXpansion:release:tag 2.0.1.12
    ```
5. Manually update release notes in github.
6. Check the release branch for the application and merge if necessery.
7. Update mp-expansion.com 

## Improvements needed. 

The tool is not perfect. 

* Make more generic? 