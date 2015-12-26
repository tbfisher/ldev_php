<?php

/**
 * @file
 * Main file for `drush:aliases` command.
 */

namespace ChapterThree\LocalDev\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use phpseclib\Crypt\RSA;
use phpseclib\Net\SSH2;

/**
 * Base class for `ldev` commands.
 */
abstract class BaseCommand extends Command {

  /**
   * Environment configuration.
   *
   * @var object
   */
  protected $conf;

  /**
   * SSH object to run commands with.
   *
   * @var \phpseclib\Net\SSH2
   */
  protected $ssh;

  /**
   * SSH config.
   */
  protected $sshConfig;

  /**
   * Implements __construct().
   *
   * @param object $conf
   *   The parsed contents of .ldev.
   * @param string|null $name
   *   The name of the command; passing null means it must be set in
   *   configure().
   *
   * @throws \LogicException
   *   When the command name is empty.
   */
  public function __construct(&$conf, $name = NULL) {
    $this->conf = &$conf;
    parent::__construct($name);
  }

  /**
   * {@inheritdoc}
   */
  protected function configure() {

    $this
      ->addOption(
        'remote',
        'r',
        InputOption::VALUE_REQUIRED,
        'Host of machine running docker, format user@hostname:port. Requires --identity, cannot be used with --vagrant.',
        NULL
      )
      ->addOption(
        'identity',
        'i',
        InputOption::VALUE_REQUIRED,
        'Private key to use with SSH. Required for --remote. By default searches ~/.ssh for id_dsa, id_ecdsa, id_ed25519, id_rsa',
        NULL
      )
      ->addOption(
        'vagrant',
        NULL,
        InputOption::VALUE_NONE,
        'Docker is running inside a Vagrant virtual machine, defined in the current directory. Cannot be used with --remote.'
      );

  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    if (!@$this->conf->version) {
      throw new \Exception('No .ldev configuration file found. Run `ldev init`.');
    }
  }

  /**
   * Get root directory.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   Input object.
   *
   * @return string
   *   No trailing slash.
   */
  public function getRootDirectory(InputInterface $input) {
    if ($input->getOption('vagrant') || $input->getOption('remote')) {
      return '/opt';
    }
    return getcwd();
  }

  /**
   * Expand "~" in path.
   *
   * @param string $path
   *   Path.
   *
   * @return string
   *   Path.
   */
  public static function expandTilde($path) {
    if (function_exists('posix_getuid') && strpos($path, '~') !== FALSE) {
      $info = posix_getpwuid(posix_getuid());
      $path = str_replace('~', $info['dir'], $path);
    }
    return $path;
  }

  /**
   * Gets ssh_config for remote via SSH.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   Input object.
   *
   * @return array
   *   See man ssh_config.
   *
   * @throws \Symfony\Component\Process\Exception\ProcessFailedException
   *   If vagrant-ssh process fails.
   * @throws \Exception
   *   Upon other error.
   */
  public function getRemote(InputInterface $input) {
    if (!isset($this->sshConfig)) {
      $remote = $input->getOption('remote');
      $vagrant = $input->getOption('vagrant');
      if ($remote && $vagrant) {
        throw new \Exception('--remote and --vagrant cannot both be specified.');
      }
      if ($remote) {
        $user = NULL;
        if (strpos($remote, '@') !== FALSE) {
          list($user, $host) = explode('@', $remote);
        }
        else {
          throw new \Exception('No user in remote "' . $remote . '".');
        }
        $port = '22';
        if (strpos($host, ':') !== FALSE) {
          list($host, $port) = explode(':', $remote);
        }
        $identity = $input->getOption('identity');
        if (empty($identity)) {
          $paths = [
            '~/.ssh/id_dsa',
            '~/.ssh/id_ecdsa',
            '~/.ssh/id_ed25519',
            '~/.ssh/id_rsa',
          ];
          foreach ($paths as $path) {
            $path = self::expandTilde($path);
            if (file_exists($path)) {
              $identity = $path;
              break;
            }
          }
        }
        else {
          $identity = self::expandTilde($identity);
        }
        if (empty($identity)) {
          throw new \Exception('Could not find private key.');
        }
        $this->sshConfig = [
          'User' => $user,
          'HostName' => $host,
          'Port' => $port,
          'IdentityFile' => $identity,
        ];
      }
      elseif ($vagrant) {
        // Use `vagrant ssh-config` to get connection config.
        $process = new Process('vagrant ssh-config');
        $process->run();
        if (!$process->isSuccessful()) {
          throw new ProcessFailedException($process);
        }
        $this->sshConfig = [];
        foreach (explode("\n", $process->getOutput()) as $line) {
          if (!preg_match('/^ +([^ ]+) +("?)(.*)\2$/', $line, $matches) ||
            empty($matches[3])
          ) {
            continue;
          }
          list(, $key, , $value) = $matches;
          $this->sshConfig[$key] = rtrim($value);
        }
        // Required.
        foreach (['HostName', 'User', 'Port', 'IdentityFile'] as $key) {
          if (empty($this->sshConfig[$key])) {
            throw new \Exception(
              'Could not determine ' . $key . ' from `vagrant ssh-config`.');
          }
        }
      }
      else {
        $this->sshConfig = FALSE;
      }
    }

    return $this->sshConfig;
  }


  /**
   * Execute a command.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   Input object.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   Output object.
   * @param string $cmd
   *   Command to execute.
   * @param bool $print
   *   Write output using $output.
   * @param int $timeout
   *   Timeout in seconds for the command to execute.
   *
   * @return array
   *   Array containing:
   *   - String command output.
   *   - Boolean TRUE if remote command timed out.
   *   - Integer return status of command.
   *
   * @throws \Exception
   *   If run remotely, throws exception upon error.
   */
  protected function exec(InputInterface $input, OutputInterface $output, $cmd, $print = FALSE, $timeout = 60) {

    // Remote.
    $this->getRemote($input);
    if ($this->sshConfig) {

      // Instantiate SSH object.
      if (empty($this->ssh)) {
        define('NET_SSH2_LOGGING', SSH2::LOG_SIMPLE);
        $sock = fsockopen(
          $this->sshConfig['HostName'],
          $this->sshConfig['Port'],
          $err_num, $err_str);
        if (!$sock) {
          throw new \Exception('Remote ssh socket failed: ' . $err_num . ' ' . $err_str);
        }
        $this->ssh = new SSH2($sock, NULL, $timeout);
        $key = new RSA();
        $key->loadKey(file_get_contents($this->sshConfig['IdentityFile']));
        if (!$this->ssh->login($this->sshConfig['User'], $key)) {
          throw new \Exception('Remote ssh login failed: ' .
            implode("\n", $this->ssh->getLog()));
        }
      }

      // Execute.
      if ($print) {
        $out = $this->ssh->exec($cmd, [$output, 'write']);
      }
      else {
        $out = $this->ssh->exec($cmd);
      }
      $status = $this->ssh->getExitStatus();
      $timeout = $this->ssh->isTimeout();
    }

    // Local.
    else {
      $timeout = FALSE;
      exec($cmd, $out, $status);
      if ($print) {
        $output->write($out);
      }
    }

    return [$out, $timeout, $status];

  }

}
