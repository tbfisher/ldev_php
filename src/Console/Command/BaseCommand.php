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
use phpseclib\Net\SFTP;
use Symfony\Component\Yaml\Parser;

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
   * @var \phpseclib\Net\SFTP
   */
  protected $ssh;

  /**
   * SSH object to run commands with.
   *
   * @var array(\phpseclib\Net\SFTP)
   */
  protected $sshWeb;

  /**
   * SSH config.
   */
  protected $sshConfig;

  /**
   * Project configuration.
   */
  protected $projects;

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
   * Parses project ldev.yml and docker-compose.yml.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   Input object.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   Output object.
   *
   * @throws \Exception
   *   Upon other error.
   */
  public function getProjects(InputInterface $input, OutputInterface $output) {
    if (isset($this->projects)) {
      return;
    }

    // Each alias keyed on name.
    $this->projects = [];

    // Parse `docker ps` output.
    list($ps,, $exit_status) = $this->exec($input, $output,
      "docker ps --format='{{.Names}}  {{.Ports}}'");
    if ($exit_status) {
      throw new \Exception('`docker ps` error: ' . $ps);
    }
    $ps = trim($ps);
    if (!empty($ps)) {
      // Each container.
      foreach (explode("\n", $ps) as $line) {

        // Parse.
        list($machine, $ports) = preg_split('/\\s+/', $line, 2);
        list($build, $container, $index) = explode('_', $machine);
        if ($index > 1) {
          $build .= '.' . $index;
        }
        $this->projects[$build]['ps'][$container] = [
          'id' => $machine,
          'ports' => [],
        ];

        if (!isset($this->projects[$build]['docker-compose'])) {
          $yaml = new Parser();
          $dir = $this->getRootDirectory($input) .
            '/provision/docker/compose/' .
            $build;

          // Parse docker-compose config.
          list($data,, $exit_status) = $this->exec($input, $output,
            "cat {$dir}/docker-compose.yml");
          if ($exit_status) {
            throw new \Exception('error: ' . $data);
          }
          else {
            $this->projects[$build]['docker-compose'] = $yaml->parse($data);
          }

          // Parse ldev config.
          list($data,, $exit_status) = $this->exec($input, $output,
            "cat {$dir}/ldev.yml");
          if ($exit_status) {
            throw new \Exception('error: ' . $data);
          }
          else {
            $this->projects[$build]['ldev'] = $yaml->parse($data);
          }
        }

        // Find needed ports.
        foreach (explode(', ', $ports) as $port) {
          // 0.0.0.0:32776->22/tcp'.
          if (strpos($port, '->') === FALSE) {
            continue;
          }
          list($public, $private) = explode('->', $port);
          $this->projects[$build]['ps'][$container]['ports'][$private] = $public;
          switch ($private) {

            // SSH.
            case '22/tcp':
              $this->projects[$build]['ports']['ssh'] = explode(':', $public)[1];
              break;

            // HTTP.
            case '80/tcp':
              $this->projects[$build]['ports']['http'] = explode(':', $public)[1];
              break;

            // HTTPS.
            case '443/tcp':
              $this->projects[$build]['ports']['https'] = explode(':', $public)[1];
              break;

            // MYSQL.
            case $this->projects[$build]['ldev']['db']['port']:
              $this->projects[$build]['ports']['db'] = explode(':', $public)[1];
              break;

          }
        }

      }
    }
  }

  /**
   * Gets fully qualified path to ssh private key for current user.
   *
   * @param string|null $identity
   *   Path to private key file.
   *
   * @return null|string
   *   Path.
   *
   * @throws \Exception
   *   Could not find private key.
   */
  public function getSshPrivateKey($identity = NULL) {
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
    return $identity;
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
        $identity = $this->getSshPrivateKey($input->getOption('identity'));
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
   * Execute a command on docker host.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   Input object.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   Output object.
   * @param string|NULL $cmd
   *   Command to execute, if NULL then just creates connection.
   * @param bool $print
   *   Write output using $output.
   * @param int $timeout
   *   Timeout in seconds for the command to execute.
   *
   * @return array|NULL
   *   If $cmd supplied, array containing:
   *   - String command output.
   *   - Boolean TRUE if remote command timed out.
   *   - Integer return status of command.
   *
   * @throws \Exception
   *   If run remotely, throws exception upon error.
   */
  public function exec(InputInterface $input, OutputInterface $output, $cmd, $print = FALSE, $timeout = 60) {

    // Remote.
    $this->getRemote($input);
    if ($this->sshConfig) {

      // Instantiate SSH object.
      if (empty($this->ssh)) {
        $sock = fsockopen(
          $this->sshConfig['HostName'],
          $this->sshConfig['Port'],
          $err_num, $err_str);
        if (!$sock) {
          throw new \Exception('Remote ssh socket failed: ' . $err_num . ' ' . $err_str);
        }
        $this->ssh = new SFTP($sock, NULL, $timeout);
        $key = new RSA();
        $key->loadKey(file_get_contents($this->sshConfig['IdentityFile']));
        if (!$this->ssh->login($this->sshConfig['User'], $key)) {
          throw new \Exception('Remote ssh login failed: ' .
            implode("\n", $this->ssh->getLog()));
        }
      }

      if ($cmd === NULL) {
        return NULL;
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
      if ($cmd === NULL) {
        return NULL;
      }

      $timeout = FALSE;
      exec($cmd, $out, $status);
      if ($print) {
        $output->write($out);
      }
    }

    return [$out, $timeout, $status];

  }

  /**
   * Gets ssh connection for "web" container.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   Input object.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   Output object.
   * @param string|null $env
   *   If null, uses "environment" argument.
   *
   * @return array
   *   User, host, port, ssh options (ssh command arguments), ssh config.
   *
   * @throws \Exception
   *   On other error.
   */
  public function getRemoteWeb(InputInterface $input, OutputInterface $output, $env = NULL) {

    if (empty($env)) {
      $env = $env = $input->getArgument('environment');
    }
    if (empty($env)) {
      throw new \Exception('Could not determine project to get web ssh for.');
    }

    $user = 'root';

    $host = 'localhost';
    $this->getRemote($input);
    if ($this->sshConfig) {
      $host = $this->sshConfig['HostName'];
    }

    $this->getProjects($input, $output);
    $port = $this->projects[$env]['ports']['ssh'];

    $ssh_options = '';
    $ssh_config = [
      'User' => $user,
      'HostName' => $host,
      'Port' => $port,
      'ForwardAgent' => 'yes',
    ];
    foreach ($this->sshConfig as $key => $val) {
      if (!in_array($key, ['HostName', 'User', 'Port', 'IdentityFile'])) {
        $ssh_options .= " -o '$key $val'";
        $ssh_config[$key] = $val;
      }
    }

    $ssh_config['IdentityFile']
      = $this->getSshPrivateKey($input->getOption('identity'));

    return [$user, $host, $port, $ssh_options, $ssh_config];
  }

  /**
   * Execute a command on web host.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   Input object.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   Output object.
   * @param string|NULL $cmd
   *   Command to execute, if NULL then just creates connection.
   * @param string $env
   *   The project defining the web host.
   * @param bool $print
   *   Write output using $output.
   * @param int $timeout
   *   Timeout in seconds for the command to execute.
   *
   * @return array|NULL
   *   If $cmd supplied, array containing:
   *   - String command output.
   *   - Boolean TRUE if remote command timed out.
   *   - Integer return status of command.
   *
   * @throws \Exception
   *   If run remotely, throws exception upon error.
   */
  public function execWeb(InputInterface $input, OutputInterface $output, $cmd = NULL, $env = NULL, $print = FALSE, $timeout = 60) {

    if (empty($env)) {
      $env = $input->getArgument('environment');
    }

    list(, , , , $ssh_config) = $this->getRemoteWeb($input, $output, $env);

    // Instantiate SSH object.
    if (empty($this->sshWeb[$env])) {
      $sock = fsockopen(
        $ssh_config['HostName'],
        $ssh_config['Port'],
        $err_num, $err_str);
      if (!$sock) {
        throw new \Exception('Remote ssh socket failed: ' . $err_num . ' ' . $err_str);
      }

      $this->sshWeb[$env] = new SFTP($sock, NULL, $timeout);
      $key = new RSA();
      $key->loadKey(file_get_contents($ssh_config['IdentityFile']));
      if (!$this->sshWeb[$env]->login($ssh_config['User'], $key)) {
        throw new \Exception('Remote ssh login failed: ' .
          implode("\n", $this->sshWeb[$env]->getLog()));
      }
    }

    if ($cmd === NULL) {
      return NULL;
    }

    // Execute.
    if ($print) {
      $out = $this->sshWeb[$env]->exec($cmd, [$output, 'write']);
    }
    else {
      $out = $this->sshWeb[$env]->exec($cmd);
    }
    $status = $this->sshWeb[$env]->getExitStatus();
    $timeout = $this->sshWeb[$env]->isTimeout();

    return [$out, $timeout, $status];
  }

}
