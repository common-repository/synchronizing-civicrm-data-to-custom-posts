<?php
/*
Plugin Name: Synchronizing CiviCRM data to Custom Posts
Description: Provides a tool for synchronizing CiviCRM data to custom posts in Wordpress. You can use this plugin with Connector to CiviCRM with CiviMcRestFace (https://wordpress.org/plugins/connector-civicrm-mcrestface/)
Version:     1.0.4
Author:      Jaap Jansma
License:     AGPL3
License URI: https://www.gnu.org/licenses/agpl-3.0.html
Text Domain: synchronizing-civicrm-data-to-custom-posts
*/

/**
 * Copyright (C) 2021  Jaap Jansma (jaap.jansma@civicoop.org)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

define('SYNC_CIVICRM_CUSTOM_POST_PATH', plugin_dir_path( __FILE__ ));
define('SYNC_CIVICRM_CUSTOM_POST_LANG', 'SYNC_CIVICRM_CUSTOM_POST');

require_once(SYNC_CIVICRM_CUSTOM_POST_PATH.'SyncCiviCustomPost.class.php');
SyncCiviCustomPost::loadAll();
if (is_admin()) {
  require_once(SYNC_CIVICRM_CUSTOM_POST_PATH . 'admin/admin.php');
}


/**
 * Wrapper function for the CiviCRM api's.
 * We use profiles to connect to different remote CiviCRM.
 *
 * @param $profile
 * @param $entity
 * @param $action
 * @param $params
 * @param array $options
 * @param bool $ignore
 *
 * @return array|mixed|null
 */
function sync_civicrm_custom_post_api_wrapper($profile, $entity, $action, $params, $options=[], $ignore=false) {
  $profiles = sync_civicrm_custom_post_get_profiles();
  if (isset($profiles[$profile])) {
    if (isset($profiles[$profile]['file'])) {
      require_once($profiles[$profile]['file']);
    }
    $result = call_user_func($profiles[$profile]['function'], $profile, $entity, $action, $params, $options);
  } else {
    $result = ['error' => 'Profile not found', 'is_error' => 1];
  }
  if (!empty($result['is_error']) && $ignore) {
    return null;
  }
  return $result;
}

/**
 * Call the CiviCRM api through CiviMcRestFace.
 *
 * @param $profile
 * @param $entity
 * @param $action
 * @param $params
 * @param array $options
 *
 * @return mixed
 */
function sync_civicrm_custom_post_wpcmrf_api($profile, $entity, $action, $params, $options = []) {
  $profile_id = substr($profile, 15);
  $call = wpcmrf_api($entity, $action, $params, $options, $profile_id);
  return $call->getReply();
}

/**
 * Returns a list of possible profiles
 * @return array
 */
function sync_civicrm_custom_post_get_profiles() {
  static $profiles = null;
  if (is_array($profiles)) {
    return $profiles;
  }

  $profiles = array();
  require_once(SYNC_CIVICRM_CUSTOM_POST_PATH.'includes/class-local-civicrm.php');
  $profiles = Sync_CiviCRM_CustomPost_Connector_LocalCiviCRM::loadProfile($profiles);

  if (function_exists('wpcmrf_get_core')) {
    $core = wpcmrf_get_core();
    $wpcmrf_profiles = $core->getConnectionProfiles();
    foreach($wpcmrf_profiles as $profile) {
      $profile_name = 'wpcmrf_profile_'.$profile['id'];
      $profiles[$profile_name] = [
        'title' => $profile['label'],
        'function' => 'sync_civicrm_custom_post_wpcmrf_api',
      ];
    }
  }
  $profiles = apply_filters('sync_civicrm_custom_post_get_profiles', $profiles);
  return $profiles;
}
