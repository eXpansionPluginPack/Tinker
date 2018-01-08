# eXpansion Tinker a way to release eXpansion

This command allows to easily create a new eXpansion release. It will : 
* Update eXpansion source code to update version number.
* Create a new tag 
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
6. Update mp-expansion.com 

## Improvements needed. 

The tool is not perfect. 

* It should update the application repositories `composer.json` in order to be sure it's the proper version.

* Think of a way to handle change logs.

* Handle multi branches for sources. (Version 2.0 from branch 2.0, 21 from 2.1 and so on.)'

* Make more generic? 