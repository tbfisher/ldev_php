<?php

/**
 * @file
 * Main file for `compose:ps` command.
 */

namespace ChapterThree\LocalDev\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class for `ldev` command.
 */
class ComposePsCommand extends BaseCommand {

  /**
   * {@inheritdoc}
   */
  protected function configure() {

    parent::configure();
    $this
      ->setName('ps')
      ->setDescription('List running containers.')
      ->addArgument(
          'environment',
          InputArgument::OPTIONAL,
          'The directory name containing the docker-compose.yml of the environment to list.'
      );

  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    parent::execute($input, $output);

    if ($env = $input->getArgument('environment')) {
      $dir = $this->getRootDirectory($input) .
        '/provision/docker/compose/' .
        $input->getArgument('environment');
      $cmd = "cd {$dir} && docker-compose ps";
    }
    else {
      $cmd = 'docker ps --format=\'table {{.Names}}\t{{.Status}}\t{{.Ports}}\'';
    }

    $this->exec($input, $output, $cmd, TRUE);

  }

}
