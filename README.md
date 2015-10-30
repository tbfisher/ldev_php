A tool for managing Drupal development sites.

## Requirements

Requires [Docker](https://www.docker.com/what-docker) and [Docker Compose](https://www.docker.com/docker-compose) to be installed on destination platforms. The destination platform can be local (linux only), a vagrant virtual machine, or remote via SSH.

On the local

-   Composer
-   PHP 5.4 or greater
-   Drush 7 and 8

## Install

```shell
composer install
sudo ln -s ~/Projects/ldev/ldev /usr/bin/ldev
```

## Setup

This tool assumes the directory structure:

-   A `.ldev` file as generated with `ldev init` in the current directory or ancestor of the current directory.
-   A project root directory that is the current directory, and contains
    -   `./code/[project]` - Code files for each project, to be shared with containers.
    -   './provision/docker/build/[image]/Dockerfile' - Any docker images.
    -   './provision/docker/compose/[project]/docker-compose.yml' - Docker Compose definitions for each project.

Also:

-   Each project defines a server container with ports 80 or 443 exposed. This container must also:
    -   Expose port 22 (ssh).
    -   Have drush installed, and a drush executable on the system path with name `drush-remote`.
-   Each project defines a mysql container with port 3306 exposed.

## Usage

Get help

```shell
ldev
```
