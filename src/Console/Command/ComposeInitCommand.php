<?php

/**
 * @file
 * Main file for `compose:init` command.
 */

namespace ChapterThree\LocalDev\Console\Command;

use ChapterThree\LocalDev\Conf;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class for `ldev` command.
 */
class ComposeInitCommand extends Command {

  /**
   * {@inheritdoc}
   */
  protected function configure() {

    parent::configure();
    $this
      ->setName('init')
      ->setDescription('Initialize a project directory.');

  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {

    $filename = getcwd() . '/.ldev';
    if (file_exists($filename)) {
      throw new \Exception($filename . ' already exists in the current directory.');
    }

    $json = json_encode([
      'namespace' => Conf::NAMESPACE_STRING,
      'version' => Conf::VERSION,
    ], JSON_PRETTY_PRINT);
    file_put_contents($filename, $json);

  }

}
