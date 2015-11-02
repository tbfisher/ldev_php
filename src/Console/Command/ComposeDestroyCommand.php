<?php

/**
 * @file
 * Main file for `compose:destroy` command.
 */

namespace ChapterThree\LocalDev\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Class for `ldev` command.
 */
class ComposeDestroyCommand extends BaseCommand {

  /**
   * {@inheritdoc}
   */
  protected function configure() {

    parent::configure();
    $this
      ->setName('destroy')
      ->setDescription('Destroy a development environment.')
      ->addArgument(
        'environment',
        InputArgument::REQUIRED,
        'The directory name containing the docker-compose.yml of the environment to destroy.'
      );

  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    parent::execute($input, $output);

    $helper = $this->getHelper('question');
    $question = new ConfirmationQuestion('This will destroy any data stored in containers. Continue? (y|n)', TRUE);

    if (!$helper->ask($input, $output, $question)) {
      return;
    }

    $dir = $this->getRootDirectory($input) .
      '/provision/docker/compose/' .
      $input->getArgument('environment');

    $cmd = "cd {$dir} && docker-compose stop";
    $this->exec($input, $output, $cmd, TRUE);

    $cmd = "cd {$dir} && docker-compose rm -f";
    $this->exec($input, $output, $cmd, TRUE);

  }

}
