<?php

if (isset($_SERVER['HTTP_APX_INCOMING_HOST'])) {
  $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_APX_INCOMING_HOST'];
}

if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
  $ips = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
  $clientIp = filter_var($ips[0], FILTER_VALIDATE_IP);
  if ($clientIp !== false) {
    $_SERVER['REMOTE_ADDR'] = $clientIp;
  }
}

/**
 * @file
 * The PHP page that serves all page requests on a Drupal installation.
 *
 * All Drupal code is released under the GNU General Public License.
 * See COPYRIGHT.txt and LICENSE.txt files in the "core" directory.
 */

use Drupal\Core\DrupalKernel;

require_once 'autoload_runtime.php';

return static function () {
  return new DrupalKernel('prod', require 'autoload.php');
};
