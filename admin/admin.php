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

class SyncCiviCustomPostAdmin {

  /**
   * @var SyncCiviCustomPostAdmin
   */
  private static $instance;

  private $textDomain = SYNC_CIVICRM_CUSTOM_POST_LANG;

  private function __construct() {
    if ( is_admin() ) {
      add_action('init', [$this, 'registerPostType']);
      add_action('save_post', [$this, 'saveMetabox'], 10, 2 );
      add_filter('manage_sync_civi_profile_posts_columns', [$this, 'columns']);
      add_filter('post_row_actions', [$this, 'rowActions'], 10, 2);
      add_filter('bulk_actions-edit-sync_civi_profile', [$this, 'bulkActions'], 10, 1);
      add_filter('disable_months_dropdown', [$this, 'disableMonthsDropdown'], 10, 2);
    }
  }

  /**
   * @return \SyncCiviCustomPostAdmin
   */
  public static function instance() {
    if (!self::$instance) {
      self::$instance = new SyncCiviCustomPostAdmin();
    }
    return self::$instance;
  }

  /**
   * Register the post type for the profile
   */
  public function registerPostType() {
    $icon = file_get_contents( SYNC_CIVICRM_CUSTOM_POST_PATH . '/assets/admin-icon.svg' );
    $labels = array(
      'name' => __('CiviCRM Data Synchronisations', 'SYNC_CIVICRM_CUSTOM_POST'),
      'singular_name' => __('CiviCRM Data Synchronisation', 'SYNC_CIVICRM_CUSTOM_POST'),
      'menu_name' => __('CiviCRM Custom Posts', 'SYNC_CIVICRM_CUSTOM_POST'),
      'name_admin_bar' => __('CiviCRM Custom Posts', 'SYNC_CIVICRM_CUSTOM_POST'),
      'all_items' => __('All Custom Posts', 'SYNC_CIVICRM_CUSTOM_POST'),
      'add_new_item' => __('Add New CiviCRM Custom Post', 'SYNC_CIVICRM_CUSTOM_POST'),
      'add_new' => __('Add New', 'SYNC_CIVICRM_CUSTOM_POST'),
      'new_item' => __('New  CiviCRM Custom Custom Post', 'SYNC_CIVICRM_CUSTOM_POST'),
      'edit_item' => __('Edit CiviCRM Custom Custom Post', 'SYNC_CIVICRM_CUSTOM_POST'),
      'update_item' => __('Update CiviCRM Custom Custom Post', 'SYNC_CIVICRM_CUSTOM_POST'),
      'view_item' => __('View CiviCRM Custom Custom Post', 'SYNC_CIVICRM_CUSTOM_POST'),
      'view_items' => __('View CiviCRM Custom Custom Post', 'SYNC_CIVICRM_CUSTOM_POST'),
      'search_items' => __('Search CiviCRM Custom Custom Post', 'SYNC_CIVICRM_CUSTOM_POST'),
    );

    $args = [
      'labels' => $labels,
      'public' => false,
      'show_ui' => true,
      'show_in_menu' => true,
      'publicly_queryable' => false,
      'exclude_from_search' => true,
      'show_in_rest' => false,
      'has_archive' => false,
      'register_meta_box_cb' => [$this, 'addMetabox'],
      'supports' => [
        'title',
      ],
      'menu_position' => 99,
      'rewrite' => false,
      'menu_icon' => 'data:image/svg+xml;base64,' . base64_encode($icon),
    ];

    register_post_type('sync_civi_profile', $args);
  }

  public function columns($columns) {
    unset($columns['date']);
    return $columns;
  }

  public function disableMonthsDropdown($return, $post_type) {
    if ($post_type == 'sync_civi_profile') {
      return TRUE;
    }
    return $return;
  }

  public function rowActions($actions, WP_Post $post) {
    if ($post->post_type == 'sync_civi_profile') {
      unset($actions['inline hide-if-no-js']);
    }
    return $actions;
  }

  public function bulkActions($actions) {
    unset($actions['edit']);
    return $actions;
  }

  /**
   * Add the metabox to the sync profile post type.
   *
   * @param $post
   */
  public function addMetabox($post) {
    add_meta_box(
      'sync_civi_profile',
      __( 'Sync Profile Settings', 'SYNC_CIVICRM_CUSTOM_POST'),
      [$this, 'renderMetabox'],
      'sync_civi_profile',
      'normal',
      'default'
    );
    add_meta_box(
      'sync_civi_profile_custom_field_info',
      __( 'Custom Fields', 'SYNC_CIVICRM_CUSTOM_POST'),
      [$this, 'customFieldInfo'],
      'sync_civi_profile',
      'side',
      'default'
    );
    remove_meta_box('submitdiv', 'sync_civi_profile', 'side');
  }

  public function saveMetabox($post_id, WP_Post $post) {
    // Add nonce for security and authentication.
    $nonce_name   = isset( $_POST['sync_civi_profile'] ) ? $_POST['sync_civi_profile'] : '';
    $nonce_action = 'sync_civi_profile_nonce_action';
    // Check if nonce is valid.
    if ( ! wp_verify_nonce( $nonce_name, $nonce_action ) ) {
      return;
    }

    // Check if user has permissions to save data.
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
      return;
    }

    // Check if not an autosave.
    if ( wp_is_post_autosave( $post_id ) ) {
      return;
    }

    // Check if not a revision.
    if ( wp_is_post_revision( $post_id ) ) {
      return;
    }

    $sync_civi_profile_api_profile = sanitize_text_field($_POST['sync_civi_profile_api_profile']);
    $sync_civi_profile_api_entity = sanitize_text_field($_POST['sync_civi_profile_api_entity']);
    $sync_civi_profile_api_get = sanitize_text_field($_POST['sync_civi_profile_api_get']);
    $sync_civi_profile_api_get_count = sanitize_text_field($_POST['sync_civi_profile_api_get_count']);
    $sync_civi_profile_id_field = sanitize_text_field($_POST['sync_civi_profile_id_field']);
    $sync_civi_profile_title_field = sanitize_text_field($_POST['sync_civi_profile_title_field']);
    $sync_civi_profile_sync_interval = sanitize_text_field($_POST['sync_civi_profile_sync_interval']);
    $sync_civi_profile_already_registered = $_POST['sync_civi_profile_already_registered'] ? 1 : 0;
    $sync_civi_profile_post_name = sanitize_text_field($_POST['sync_civi_profile_post_name']);

    update_post_meta($post_id, 'sync_civi_profile_api_profile', $sync_civi_profile_api_profile);
    update_post_meta($post_id, 'sync_civi_profile_api_entity', $sync_civi_profile_api_entity);
    update_post_meta($post_id, 'sync_civi_profile_api_get', $sync_civi_profile_api_get);
    update_post_meta($post_id, 'sync_civi_profile_api_get_count', $sync_civi_profile_api_get_count);
    update_post_meta($post_id, 'sync_civi_profile_id_field', $sync_civi_profile_id_field);
    update_post_meta($post_id, 'sync_civi_profile_title_field', $sync_civi_profile_title_field);
    update_post_meta($post_id, 'sync_civi_profile_sync_interval', $sync_civi_profile_sync_interval);
    update_post_meta($post_id, 'sync_civi_profile_already_registered', $sync_civi_profile_already_registered);
    update_post_meta($post_id, 'sync_civi_profile_post_name', $sync_civi_profile_post_name);
  }

  public function customFieldInfo(WP_Post $post, $metabox) {
    $fields = array();
    $meta = get_post_meta($post->ID);
    $sync_civi_profile_api_profile = isset($meta['sync_civi_profile_api_profile']) ? reset($meta['sync_civi_profile_api_profile']) : '';
    $sync_civi_profile_api_entity = isset($meta['sync_civi_profile_api_entity']) ? reset($meta['sync_civi_profile_api_entity']) : '';
    $sync_civi_profile_api_get = isset($meta['sync_civi_profile_api_egt']) ? reset($meta['sync_civi_profile_api_get']) : 'Get';
    $sync_civi_profile_api_get_count = isset($meta['sync_civi_profile_api_get_count']) ? reset($meta['sync_civi_profile_api_get_count']) : 'Getcount';
    $sync_civi_profile_id_field = isset($meta['sync_civi_profile_id_field']) ? reset($meta['sync_civi_profile_id_field']) : 'id';
    $sync_civi_profile_title_field = isset($meta['sync_civi_profile_title_field']) ? reset($meta['sync_civi_profile_title_field']) : '';
    $sync_civi_profile_sync_interval = isset($meta['sync_civi_profile_sync_interval']) ? reset($meta['sync_civi_profile_sync_interval']) : '10';
    $sync_civi_profile_already_registered = isset($meta['sync_civi_profile_already_registered']) ? reset($meta['sync_civi_profile_already_registered']) : '';    
    $sync_civi_profile_post_name = isset($meta['sync_civi_profile_post_name']) ? reset($meta['sync_civi_profile_post_name']) : '';
    $post_type = sanitize_key($sync_civi_profile_post_name);
    if ($sync_civi_profile_api_profile && $sync_civi_profile_api_entity && $sync_civi_profile_api_get) {
        $fieldApiParams['api_action'] = lcfirst($sync_civi_profile_api_get);
        $fieldApiOptions['cache'] = '5 minutes';
        $fieldApiResults = sync_civicrm_custom_post_api_wrapper($sync_civi_profile_api_profile, $sync_civi_profile_api_entity, 'getfields', $fieldApiParams, $fieldApiOptions);
        foreach($fieldApiResults['values'] as $fieldApiResult) {
            $fields[$fieldApiResult['name']] = $fieldApiResult['title'];
        }
    }
    if (count($fields)) {
        ?>
        <p><?php _e('Fields', 'SYNC_CIVICRM_CUSTOM_POST') ?></p>
        <ul>
            <?php foreach($fields as $field => $label) {
                echo '<li><strong>' . esc_html($label) . '</strong>: ' . esc_html($post_type) . '_civicrm_' . esc_html($field) . '</li>';
            } ?>
        </ul>
        <?php
    }
  }

  /**
   * Render the metabox
   */
  public function renderMetabox(WP_Post $post, $metabox) {
    $meta = get_post_meta($post->ID);
    $sync_civi_profile_api_profile = isset($meta['sync_civi_profile_api_profile']) ? reset($meta['sync_civi_profile_api_profile']) : '';
    $sync_civi_profile_api_entity = isset($meta['sync_civi_profile_api_entity']) ? reset($meta['sync_civi_profile_api_entity']) : '';
    $sync_civi_profile_api_get = isset($meta['sync_civi_profile_api_egt']) ? reset($meta['sync_civi_profile_api_get']) : 'Get';
    $sync_civi_profile_api_get_count = isset($meta['sync_civi_profile_api_get_count']) ? reset($meta['sync_civi_profile_api_get_count']) : 'Getcount';
    $sync_civi_profile_id_field = isset($meta['sync_civi_profile_id_field']) ? reset($meta['sync_civi_profile_id_field']) : 'id';
    $sync_civi_profile_title_field = isset($meta['sync_civi_profile_title_field']) ? reset($meta['sync_civi_profile_title_field']) : '';
    $sync_civi_profile_sync_interval = isset($meta['sync_civi_profile_sync_interval']) ? reset($meta['sync_civi_profile_sync_interval']) : '10';
    $sync_civi_profile_already_registered = isset($meta['sync_civi_profile_already_registered']) ? reset($meta['sync_civi_profile_already_registered']) : '';    
    $sync_civi_profile_post_name = isset($meta['sync_civi_profile_post_name']) ? reset($meta['sync_civi_profile_post_name']) : '';
    wp_nonce_field( 'sync_civi_profile_nonce_action', 'sync_civi_profile' );
    ?>

    <table>
        <tr class="form-field">
            <th scope="row"><label for="sync_civi_profile_api_profile"><?php _e('Connection Profile', 'SYNC_CIVICRM_CUSTOM_POST') ?> </label></th>
            <td><select name="sync_civi_profile_api_profile" id="sync_civi_profile_api_profile">
                <?php foreach(sync_civicrm_custom_post_get_profiles() as $profile_name => $profile) { ?>
                  <option value="<?php echo esc_attr($profile_name); ?>" <?php if ($profile_name == $sync_civi_profile_api_profile) { ?> selected="selected" <?php } ?>><?php echo esc_html($profile['title']); ?></option>
                <?php } ?>
            </select></td>
        </tr>
        <tr class="form-field">
          <th scope="row"><label for="sync_civi_profile_api_entity"><?php _e('API Entity', 'SYNC_CIVICRM_CUSTOM_POST') ?> </label></th>
          <td><input name="sync_civi_profile_api_entity" type="text" id="sync_civi_profile_api_entity" value="<?php echo esc_attr($sync_civi_profile_api_entity); ?>" /></td>
        </tr>
        <tr class="form-field">
          <th scope="row"><label for="sync_civi_profile_api_get"><?php _e('API Get Action', 'SYNC_CIVICRM_CUSTOM_POST') ?> </label></th>
          <td><input name="sync_civi_profile_api_get" type="text" id="sync_civi_profile_api_get" value="<?php echo esc_attr($sync_civi_profile_api_get); ?>" /></td>
        </tr>
        <tr class="form-field">
          <th scope="row"><label for="sync_civi_profile_api_get_count"><?php _e('API Getcount Action', 'SYNC_CIVICRM_CUSTOM_POST') ?> </label></th>
          <td><input name="sync_civi_profile_api_get_count" type="text" id="sync_civi_profile_api_get_count" value="<?php echo esc_attr($sync_civi_profile_api_get_count); ?>" /></td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="sync_civi_profile_id_field"><?php _e('API ID Field', 'SYNC_CIVICRM_CUSTOM_POST') ?> </label></th>
            <td><input name="sync_civi_profile_id_field" type="text" id="sync_civi_profile_id_field" value="<?php echo esc_attr($sync_civi_profile_id_field); ?>" /></td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="sync_civi_profile_title_field"><?php _e('API Title Field', 'SYNC_CIVICRM_CUSTOM_POST') ?> </label></th>
            <td><input name="sync_civi_profile_title_field" type="text" id="sync_civi_profile_title_field" value="<?php echo esc_attr($sync_civi_profile_title_field); ?>" /></td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="sync_civi_profile_sync_interval"><?php _e('Synchronization interval (minutes)', 'SYNC_CIVICRM_CUSTOM_POST') ?> </label></th>
            <td><input name="sync_civi_profile_sync_interval" type="text" id="sync_civi_profile_sync_interval" value="<?php echo esc_attr($sync_civi_profile_sync_interval); ?>" /></td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="sync_civi_profile_already_registered"><?php _e('Already Registered', 'SYNC_CIVICRM_CUSTOM_POST') ?> </label></th>
            <td><input name="sync_civi_profile_already_registered" type="checkbox" id="sync_civi_profile_already_registered" value="1" <?php if($sync_civi_profile_already_registered) { echo 'checked';} ?> /></td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="sync_civi_profile_post_name"><?php _e('Custom Post Name', 'SYNC_CIVICRM_CUSTOM_POST') ?> </label></th>
            <td><input name="sync_civi_profile_post_name" type="text" id="sync_civi_profile_post_name" maxlength="20" value="<?php echo esc_attr($sync_civi_profile_post_name); ?>" /></td>
        </tr>
    </table>

    <div class="clear"></div>
    <div id="publishing-action">
        <?php submit_button( __( 'Save', 'SYNC_CIVICRM_CUSTOM_POST'), 'primary large', 'publish', false ); ?>
    </div>
    <div class="clear"></div>

    <?php
  }
}

if (is_admin()) {
  $syncCiviCustomPost = SyncCiviCustomPostAdmin::instance();
}
