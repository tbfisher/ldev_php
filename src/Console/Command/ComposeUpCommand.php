<?php

/**
 * @file
 * Main file for `compose:up` command.
 */

namespace ChapterThree\LocalDev\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class for `ldev` command.
 */
class ComposeUpCommand extends BaseCommand {

  /**
   * {@inheritdoc}
   */
  protected function configure() {

    parent::configure();
    $this
      ->setName('up')
      ->setDescription('Start up a development environment.')
      ->addArgument(
          'environment',
          InputArgument::REQUIRED,
          'The directory name containing the docker-compose.yml of the environment to start up.'
      );

  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    parent::execute($input, $output);

    $dir = $this->getRootDirectory($input) .
      '/provision/docker/compose/' .
      $input->getArgument('environment');

    $cmd = "cd {$dir} && docker-compose up -d";
    $this->exec($input, $output, $cmd, TRUE);

  }

}
