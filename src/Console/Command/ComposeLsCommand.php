<?php

/**
 * @file
 * Main file for `compose:ls` command.
 */

namespace ChapterThree\LocalDev\Console\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class for `ldev` command.
 */
class ComposeLsCommand extends BaseCommand {

  /**
   * {@inheritdoc}
   */
  protected function configure() {

    parent::configure();
    $this
      ->setName('compose:ls')
      ->setDescription('List development environments.');

  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    parent::execute($input, $output);

    $dir = $this->getRootDirectory($input) .
      '/provision/docker/compose/';
    $cmd = "ls {$dir}";
    $this->exec($input, $output, $cmd, TRUE);

  }

}
