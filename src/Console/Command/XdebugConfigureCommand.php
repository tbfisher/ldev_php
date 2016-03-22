<?php

/**
 * @file
 * Main file for `xdebug:configure` command.
 */

namespace ChapterThree\LocalDev\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Piwik\Ini\IniReader;
use Piwik\Ini\IniWriter;

/**
 * Class for `ldev` command.
 */
class XdebugConfigureCommand extends BaseCommand {

  /**
   * {@inheritdoc}
   */
  protected function configure() {

    parent::configure();
    $this
      ->setName('xdebug:configure')
      ->setDescription('Enable and configure xdebug to connect to host.')
      ->addArgument(
        'environment',
        InputArgument::OPTIONAL,
        'The directory name containing the docker-compose.yml of the environment to configure.'
      );

  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    parent::execute($input, $output);

    $this->getProjects($input, $output);

    if ($env = $input->getArgument('environment')) {
      $projects = [$env];
    }
    else {
      $projects = array_keys($this->projects);
    }

    // Get xdebug remote host, i.e. the host address.
    $this->getRemote($input);
    if ($this->sshConfig) {
      $cmd = <<<'EOD'
ifconfig vmnet2 | grep inet | sed -E 's/^.*inet ([0-9\.]+).*/\1/g'
EOD;
      exec($cmd, $remote_host, $status);
      if ($status !== 0) {
        throw new \Exception("Could not determine xdebug remote_host");
      }
      $remote_host = $remote_host[0];
    }
    else {
      $remote_host = 'localhost';
    }

    foreach ($projects as $env) {

      if (empty($this->projects[$env]['ldev']['xdebug']['ini'])) {
        $output->writeln("Skipping ${env}: no xdebug.ini configured in ldev.yml.");
        continue;
      }
      $ini_path = $this->projects[$env]['ldev']['xdebug']['ini'];

      $this->execWeb($input, $output, NULL, $env);
      /** @var \phpseclib\Net\SFTP $ssh */
      $ssh = $this->sshWeb[$env];

      $ini_contents = $ssh->get($ini_path);
      if ($ini_contents === FALSE) {
        throw new \Exception("Could not read xdebug ini file ${ini_path}");
      }

      $ini_reader = new IniReader();
      $ini_contents = $ini_reader->readString($ini_contents);
      if (!isset($ini_contents['xdebug'])) {
        $ini_contents = ['xdebug' => $ini_contents];
      }

      $ini_contents['xdebug']['xdebug.remote_enable'] = 1;
      $ini_contents['xdebug']['xdebug.remote_host'] = $remote_host;

      $ini_writer = new IniWriter();
      $ini_contents = $ini_writer->writeToString($ini_contents);
      $ssh->put($ini_path, $ini_contents);

      $verbose = ["Wrote to ${env} ${ini_path}:", '', $ini_contents];
      $output->writeln($verbose);
    }

  }

}
