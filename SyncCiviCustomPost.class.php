<?php
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

class SyncCiviCustomPost {

  /**
   * @var SyncCiviCustomPost[]
   */
  private static $instance = [];

  protected $post_type;

  protected $sync_profile_id;

  protected $meta;

  protected $api_profile;

  protected $api_entity;

  protected $api_get;

  protected $api_get_count;

  protected $post_name;

  protected $id_field;

  protected $sync_interval;

  protected $title_field;

  private function __construct($post_type, $postID, $meta) {
    $this->post_type = sanitize_key($post_type);
    $this->meta = $meta;
    $this->sync_profile_id = $postID;

    $this->api_profile = isset($meta['sync_civi_profile_api_profile']) ? reset($meta['sync_civi_profile_api_profile']) : '';
    $this->api_entity = isset($meta['sync_civi_profile_api_entity']) ? reset($meta['sync_civi_profile_api_entity']) : '';
    $this->api_get = isset($meta['sync_civi_profile_api_egt']) ? reset($meta['sync_civi_profile_api_get']) : 'Get';
    $this->api_get_count = isset($meta['sync_civi_profile_api_get_count']) ? reset($meta['sync_civi_profile_api_get_count']) : 'Getcount';
    $this->already_registered = isset($meta['sync_civi_profile_already_registered']) ? reset($meta['sync_civi_profile_already_registered']) : '';
    $this->post_name = isset($meta['sync_civi_profile_post_name']) ? reset($meta['sync_civi_profile_post_name']) : '';
    $this->id_field = isset($meta['sync_civi_profile_id_field']) ? reset($meta['sync_civi_profile_id_field']) : 'id';
    $this->sync_interval = isset($meta['sync_civi_profile_sync_interval']) ? reset($meta['sync_civi_profile_sync_interval']) : '';
    $this->title_field = isset($meta['sync_civi_profile_title_field']) ? reset($meta['sync_civi_profile_title_field']) : '';

    if (!$this->already_registered) {
      add_action('init', [$this, 'registerPostType']);
    }
    if ($this->sync_interval) {
      add_action('init', [$this, 'setupCron']);
    }
  }

  /**
   * @param $post_type
   * @param int $postID
   * @param array $meta
   * @return \SyncCiviCustomPost
   */
  public static function getInstance($post_type, $postID, $meta) {
    if (!isset(self::$instance[$post_type])) {
      self::$instance[$post_type] = new SyncCiviCustomPost($post_type, $postID, $meta);
    }
    return self::$instance[$post_type];
  }

  /**
   * Setup the cron.
   */
  public function setupCron() {
    add_filter('cron_schedules', function ($schedules) {
      $scheduleName = $this->getScheduleName();
      $schedules[$scheduleName] = array(
        'interval' => $this->sync_interval * 60,
        'display'  => $this->post_name . ' (Every '.$this->sync_interval.' minutes)'
      );
      return $schedules;
    });

    if (!wp_next_scheduled($this->getScheduleTaskName())) {
      wp_schedule_event(time(), $this->getScheduleName(), $this->getScheduleTaskName());
    }
    add_action($this->getScheduleTaskName(), [$this, 'sync']);
  }

  /**
   * @return string
   */
  public function getScheduleName() {
    return 'sync_civi_custom_posts_schedule_' . $this->sync_profile_id;
  }

  /**
   * @return string
   */
  public function getScheduleTaskName() {
    return 'sync_civi_custom_posts_task_' . $this->sync_profile_id;
  }

  /**
   * Synchronize this post type.
   */
  public function sync() {
    $syncedIds = array();
    $options['limit'] = 0;
    $result = sync_civicrm_custom_post_api_wrapper($this->api_profile, $this->api_entity, $this->api_get, [], $options);
    foreach($result['values'] as $row) {
      $id = $row[$this->id_field];
      $title = $row[$this->title_field];
      $wp_post_data = [
        'post_title' => esc_html($title),
        'post_type' => $this->post_type,
        'post_status' => 'publish',
      ];
      $existing_wp_post_id = $this->findExistingPost($id);
      if (!empty($existing_wp_post_id)) {
        $wp_post_data['ID'] = $existing_wp_post_id;
      }
      $wp_post_id = wp_insert_post($wp_post_data);
      update_post_meta($wp_post_id, '_sync_civicrm_custom_post_id', $id);
      foreach($row as $field => $value) {
        update_post_meta($wp_post_id, $this->post_type.'_civicrm_'.$field, $value);
      }
      $syncedIds[] = $id;
    }
    $this->deletePostsNotInCiviCRM($syncedIds);
  }

  /**
   * Load all custom posts
   */
  public static function loadAll() {
    $customPosts = get_posts([
      'post_type' => 'sync_civi_profile',
      'numberposts' => -1,  // All posts
    ]);
    foreach($customPosts as $customPost) {
      $meta = get_post_meta($customPost->ID);
      SyncCiviCustomPost::getInstance(reset($meta['sync_civi_profile_post_name']), $customPost->ID, $meta);
    }
  }

  /**
   * Register the post type
   */
  public function registerPostType() {
    $icon = file_get_contents( SYNC_CIVICRM_CUSTOM_POST_PATH . '/assets/admin-icon.svg' );
    $labels = array(
      'name' => $this->post_name,
      'singular_name' => $this->post_name,
      'menu_name' => $this->post_name,
      'name_admin_bar' => $this->post_name,
      'all_items' => __('All items', 'SYNC_CIVICRM_CUSTOM_POST'),
      'add_new_item' => __('Add', 'SYNC_CIVICRM_CUSTOM_POST'),
      'add_new' => __('Add', 'SYNC_CIVICRM_CUSTOM_POST'),
      'new_item' => __('New', 'SYNC_CIVICRM_CUSTOM_POST'),
      'edit_item' => __('Edit', 'SYNC_CIVICRM_CUSTOM_POST'),
      'update_item' => __('Update', 'SYNC_CIVICRM_CUSTOM_POST'),
      'view_item' => __('View', 'SYNC_CIVICRM_CUSTOM_POST'),
      'view_items' => __('View', 'SYNC_CIVICRM_CUSTOM_POST'),
      'search_items' => __('Search', 'SYNC_CIVICRM_CUSTOM_POST'),
    );

    $args = [
      'labels' => $labels,
      'public' => true,
      'show_ui' => true,
      'show_in_menu' => true,
      'publicly_queryable' => false,
      'exclude_from_search' => true,
      'show_in_rest' => true,
      'has_archive' => false,
      'supports' => [
        'title',
      ],
      'menu_position' => 10,
      'rewrite' => true,
      'menu_icon' => 'data:image/svg+xml;base64,' . base64_encode($icon),
    ];

    $args = apply_filters('sync_civicrm_custom_post_register_custom_post', $args, $this->post_type);

    register_post_type($this->post_type, $args);
  }


  /**
   * Find existing post
   *
   * @param $civicrmId
   *
   * @return string|null
   */
  protected function findExistingPost($civicrmId)
  {
    global $wpdb;

    $sql = "
    SELECT meta.post_id 
    FROM {$wpdb->prefix}postmeta meta
    INNER JOIN {$wpdb->prefix}posts post ON meta.post_id = post.id
    WHERE meta.meta_key = '_sync_civicrm_custom_post_id' and meta.meta_value = '$civicrmId' AND post.post_type = '{$this->post_type}'";
    return $wpdb->get_var($sql);
  }

  /**
   * Delete posts which are not in CiviCRM
   *
   * @param $civicrmIds
   */
  function deletePostsNotInCiviCRM($civicrmIds) {
    global $wpdb;
    if (!is_array($civicrmIds) || !count($civicrmIds)) {
      return;
    }

    $sql = "
    SELECT meta.post_id 
    FROM {$wpdb->prefix}postmeta meta
    INNER JOIN {$wpdb->prefix}posts post ON meta.post_id = post.id
    WHERE meta.meta_key = '_sync_civicrm_custom_post_id' and meta.meta_value NOT IN (" . implode(", ", $civicrmIds) . ") AND post.post_type = '{$this->post_type}'";
    $posts = $wpdb->get_results($sql);
    foreach($posts as $post) {
      wp_delete_post($post->post_id);
    }
  }

}
