<?php
/**
 * @file
 * Application configuration.
 */

namespace ChapterThree\LocalDev;

/**
 * Application configuration.
 */
class Conf {

  const NAMESPACE_STRING = 'name.brianfisher.ldev';
  // @see composer.json.
  const VERSION = '0.0.1';

  /**
   * Loads the configuration file.
   *
   * @todo Allow argument defaults (esp --vagrant) and merge with any conf files
   *   in ancestor directories.
   *
   * @return object|NULL
   *   Conf object or NULL if not found.
   */
  public static function load() {

    $conf = FALSE;
    $cwd = explode(DIRECTORY_SEPARATOR, getcwd());
    while ($conf === FALSE && count($cwd)) {
      $filename = implode('/', $cwd) . "/.ldev";
      if (file_exists($filename)) {
        $conf = file_get_contents($filename);
        if ($conf !== FALSE) {
          $conf = json_decode($conf);
          if ($conf !== NULL && @$conf->namespace == self::NAMESPACE_STRING) {
            break;
          }
        }
      }
      array_pop($cwd);
    }

    return $conf === FALSE ? NULL : $conf;
  }

}
