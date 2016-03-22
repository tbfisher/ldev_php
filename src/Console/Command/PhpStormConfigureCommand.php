<?php

/**
 * @file
 * Main file for `xdebug:configure` command.
 */

namespace ChapterThree\LocalDev\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class for `ldev` command.
 */
class PhpStormConfigureCommand extends BaseCommand {

  /**
   * {@inheritdoc}
   */
  protected function configure() {

    parent::configure();
    $this
      ->setName('phpstorm:configure')
      ->setDescription('Configure phpstorm.')
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

    foreach ($projects as $env) {

      $conf = &$this->projects[$env];

      // PhpStorm configuration directory path.
      if (empty($conf['ldev']['phpstorm']['project_root'])) {
        $output->writeln("Skipping ${env}: no PhpStorm project root configured in ldev.yml.");
        continue;
      }
      $project_root = getcwd() . '/code/' . $env . '/' .
        $conf['ldev']['phpstorm']['project_root'] . '/.idea';
      if (!file_exists($project_root)) {
        mkdir($project_root);
      }

      $ssh_port = $conf['ports']['ssh'];
      $interpreter_name = "Remote PHP (created by ldev)";

      // PHP config.
      $file_path = $project_root . '/php.xml';
      $doc = $this->getConfigDom($output, $file_path);
      $xpath = new \DOMXPath($doc);
      $root = $doc->documentElement;
      // Add remote interpreter.
      $existing = $xpath->query('/project/component[@name="PhpInterpreters"]');
      if ($existing->length) {
        foreach ($existing as $element) {
          $root->removeChild($element);
        }
      }
      $parent = $doc->createElement('component');
      $parent->setAttribute('name', 'PhpInterpreters');
      $root->insertBefore($parent,
        $root->hasChildNodes() ? $root->firstChild : NULL);
      $element = $doc->createElement('interpreters');
      $parent = $parent->appendChild($element);
      $element = $doc->createElement('interpreter');
      $element->setAttribute('home', "ssh://root@local.dev:${ssh_port}/usr/bin/php");
      $element->setAttribute('name', $interpreter_name);
      $element->setAttribute('debugger_id', 'php.debugger.XDebug');
      /** @var \DOMElement $parent */
      $parent = $parent->appendChild($element);
      $element = $doc->createElement('remote_data');
      $element->setAttribute('HOST', 'local.dev');
      $element->setAttribute('PORT', $ssh_port);
      $element->setAttribute('ANONYMOUS', 'false');
      $element->setAttribute('USERNAME', 'root');
      $element->setAttribute('PASSWORD', '');
      $element->setAttribute('PRIVATE_KEY_FILE', '$USER_HOME$/.ssh/id_rsa');
      $element->setAttribute('MY_KNOWN_HOSTS_FILE', '');
      $element->setAttribute('PASSPHRASE', '');
      $element->setAttribute('USE_KEY_PAIR', 'true');
      $element->setAttribute('INTERPRETER_PATH', '/usr/bin/php');
      $element->setAttribute('HELPERS_PATH', '/root/.phpstorm_helpers');
      $element->setAttribute('INITIALIZED', 'false');
      $element->setAttribute('VALID', 'true');
      $parent->appendChild($element);
      // Set remote interpreter.
      $existing = $xpath->query('/project/component[@name="PhpInterpretersPhpInfoCache"]/phpInfoCache/interpreter');
      if ($existing->length) {
        /** @var \DOMElement $element */
        $element = $existing->item(0);
      }
      else {
        $existing = $xpath->query('/project/component[@name="PhpInterpretersPhpInfoCache"]');
        if ($existing->length) {
          $parent = $existing->item(0);
        }
        else {
          $parent = $doc->createElement('component');
          $parent->setAttribute('name', 'PhpInterpretersPhpInfoCache');
          $root->appendChild($parent);
        }
        $existing = $xpath->query('/project/component[@name="PhpInterpretersPhpInfoCache"]/phpInfoCache');
        if (!$existing->length) {
          $element = $doc->createElement('phpInfoCache');
          $parent = $parent->appendChild($element);
          $element = $doc->createElement('interpreter');
          $parent->appendChild($element);
        }
      }
      if (!$element->hasAttribute('name') ||
          $element->getAttribute('name') != $interpreter_name
      ) {
        $element->setAttribute('name', $interpreter_name);
        foreach ($element->childNodes as $child) {
          $element->removeChild($child);
        }
      }
      // Set php language level.
      $php_language_level = @$conf['ldev']['phpstorm']['language_level'];
      if (empty($php_language_level)) {
        $output->writeln("Skipping setting php language level ${env}: no PhpStorm language_level configured in ldev.yml.");
      }
      $existing = $xpath->query('/project/component[@name="PhpProjectSharedConfiguration"]');
      if ($existing->length) {
        /** @var \DOMElement $element */
        $element = $existing->item(0);
      }
      else {
        $element = $doc->createElement('component');
        $element->setAttribute('name', 'PhpProjectSharedConfiguration');
        $root->appendChild($element);
      }
      $element->setAttribute('php_language_level', $php_language_level);
      // Write.
      $doc->formatOutput = TRUE;
      file_put_contents($file_path, $doc->saveXML());
      $output->writeln("Updated ${file_path}");

      // Workspace config.
      $file_path = $project_root . '/workspace.xml';
      $doc = $this->getConfigDom($output, $file_path);
      $xpath = new \DOMXPath($doc);
      // PHP Debug.
      $existing = $xpath->query('/project/component[@name="PhpDebugGeneral"]');
      if ($existing->length) {
        $element = $existing->item(0);
      }
      else {
        $element = $doc->createElement('component');
        $element->setAttribute('name', 'PhpDebugGeneral');
        $doc->documentElement->appendChild($element);
      }
      $element->setAttribute('max_simultaneous_connections', '4');
      $element->setAttribute('notify_if_session_was_finished_without_being_paused', 'false');
      // PHP servers.
      $existing = $xpath->query('/project/component[@name="PhpServers"]/servers/server');
      $server_http = $server_https = FALSE;
      if ($existing->length) {
        foreach ($existing as $element) {
          if ($element->hasAttribute('port')) {
            $port = $element->getAttribute('port');
            if ($port == $conf['ports']['http']) {
              $server_http = $element;
            }
            elseif ($port == $conf['ports']['https']) {
              $server_https = $element;
            }
            else {
              $element->parentNode->removeChild($element);
              continue;
            }
          }
        }
      }
      $existing = $xpath->query('/project/component[@name="PhpServers"]');
      if ($existing->length) {
        $parent = $existing->item(0);
      }
      else {
        $parent = $doc->createElement('component');
        $parent->setAttribute('name', 'PhpServers');
        $doc->documentElement->appendChild($parent);
      }
      $existing = $xpath->query('servers', $parent);
      if ($existing->length) {
        $parent = $existing->item(0);
      }
      else {
        $parent = $doc->createElement('servers');
        $parent->appendChild($parent);
      }
      if (!$server_http) {
        $server_http = $doc->createElement('server');
        $server_http->setAttribute('port', $conf['ports']['http']);
        $parent->appendChild($server_http);
      }
      $server_http->setAttribute('name', 'local.dev:' . $conf['ports']['http']);
      $server_http->setAttribute('host', 'local.dev');
      $server_http->setAttribute('use_path_mappings', 'true');
      if (!$server_https) {
        $server_https = $doc->createElement('server');
        $server_https->setAttribute('port', $conf['ports']['https']);
        $parent->appendChild($server_https);
      }
      $server_https->setAttribute('name', 'local.dev:' . $conf['ports']['https']);
      $server_https->setAttribute('host', 'local.dev');
      $server_https->setAttribute('use_path_mappings', 'true');
      // PHP workspace.
      $existing = $xpath->query('/project/component[@name="PhpWorkspaceProjectConfiguration"]');
      if ($existing->length) {
        $element = $existing->item(0);
      }
      else {
        $element = $doc->createElement('component');
        $element->setAttribute('name', 'PhpWorkspaceProjectConfiguration');
        $doc->documentElement->appendChild($element);
      }
      $element->setAttribute('interpreter_name', $interpreter_name);
      // Write.
      $doc->formatOutput = TRUE;
      file_put_contents($file_path, $doc->saveXML());
      $output->writeln("Updated ${file_path}");

    }
  }

  /**
   * Loads or initializes PhpStorm XML configuration file.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   Output object.
   * @param string $path
   *   File path.
   *
   * @return \DOMDocument
   *   DOM object -- if file does not exist, object initialized with root
   *   element set.
   */
  private function getConfigDom(OutputInterface $output, $path) {
    $doc = new \DOMDocument('1.0', 'UTF-8');
    if (file_exists($path)) {
      $doc->loadXML(file_get_contents($path));
      /** @var \DOMElement $root */
      $xpath = new \DOMXPath($doc);
      $root = $xpath->query('/project')->item(0);
      $version = $root->getAttribute('version');
      if ($version != 4) {
        $output->writeln("Warning: Unexpected project xml version ${version} in ${path}");
      }
    }
    else {
      $root = $doc->createElement('project');
      $root->setAttribute('version', '4');
      $doc->appendChild($root);
    }
    return $doc;
  }

}
