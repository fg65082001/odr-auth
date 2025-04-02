<?php
/*
Plugin Name: ODR Auth
Description: Verifies subscription keys for multiple plugins with admin settings.
Version: 1.5.7
Author: Shawn Su
*/

if (!defined('ABSPATH')) {
    exit;
}

// 啟用時創建驗證頁面
register_activation_hook(__FILE__, 'odr_auth_create_verify_page');
function odr_auth_create_verify_page() {
    $verify_page = get_page_by_path('verify-key');
    if (!$verify_page) {
        wp_insert_post(array(
            'post_title' => 'Verify Key',
            'post_name' => 'verify-key',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_content' => '[odr_auth_verify_key]'
        ));
    }
}

// 處理金鑰驗證表單
function odr_auth_verify_key_shortcode($atts) {
    $atts = shortcode_atts(array('plugin' => 'portfolio-player'), $atts);
    $plugin = sanitize_text_field($atts['plugin']);
    $meta_key = 'portfolio_player_key_' . $plugin;

    if (!is_page('verify-key')) {
        return '';
    }

    $message = '';

    if (isset($_POST['odr_auth_verify_key'])) {
        $key = sanitize_text_field($_POST['key'] ?? '');
        if (empty($key)) {
            $message = '<p style="color: red;">請輸入金鑰。</p>';
        } else {
            if (!is_user_logged_in()) {
                $message = '<p style="color: red;">請先登入以驗證金鑰。</p>';
            } else {
                $api_url = get_option('odr_auth_api_url', 'https://ourdaysrecords.com/boss/wp-json/odr-key/v1/check');
                $response = wp_remote_get($api_url . '?key=' . urlencode($key), array(
                    'timeout' => 15,
                    'sslverify' => false,
                ));

                if (is_wp_error($response)) {
                    $message = '<p style="color: red;">無法驗證金鑰：' . $response->get_error_message() . '</p>';
                } else {
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    if (isset($body['valid']) && $body['valid'] && isset($body['plugin']) && $body['plugin'] === $plugin && isset($body['email'])) {
                        $current_user = wp_get_current_user();
                        if ($body['email'] !== $current_user->user_email) {
                            $message = '<p style="color: red;">金鑰不匹配您的 Email，請使用購買金鑰的 Email 登入。</p>';
                        } else {
                            $user_id = $current_user->ID;
                            update_user_meta($user_id, $meta_key, $key);
                            update_user_meta($user_id, $meta_key . '_checked', time());
                            update_user_meta($user_id, $meta_key . '_valid', 'yes');
                            $message = '<p style="color: green;">金鑰驗證成功，已啟用！</p>';
                        }
                    } else {
                        $message = '<p style="color: red;">無效的金鑰或不適用於 ' . esc_html(ucfirst(str_replace('-', ' ', $plugin))) . '，請檢查後重新輸入。</p>';
                    }
                }
            }
        }
    } elseif (is_user_logged_in()) {
        $user_id = get_current_user_id();
        $stored_key = get_user_meta($user_id, $meta_key, true);

        if ($stored_key) {
            $last_checked = get_user_meta($user_id, $meta_key . '_checked', true);
            $cached_valid = get_user_meta($user_id, $meta_key . '_valid', true);
            if ($last_checked && (time() - $last_checked < 3600) && $cached_valid !== '') {
                if ($cached_valid === 'yes') {
                    $message = '<p style="color: green;">您的金鑰已啟用。</p>';
                } else {
                    delete_user_meta($user_id, $meta_key);
                }
            }

            $api_url = get_option('odr_auth_api_url', 'https://ourdaysrecords.com/boss/wp-json/odr-key/v1/check');
            $response = wp_remote_get($api_url . '?key=' . urlencode($stored_key), array(
                'timeout' => 15,
                'sslverify' => false,
            ));

            if (!is_wp_error($response)) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                $is_valid = isset($body['valid']) && $body['valid'];
                update_user_meta($user_id, $meta_key . '_checked', time());
                update_user_meta($user_id, $meta_key . '_valid', $is_valid ? 'yes' : 'no');
                if (!$is_valid) {
                    delete_user_meta($user_id, $meta_key);
                }
            }
        }
    }

    ob_start();
    ?>
    <div class="odr-auth-verify">
        <h2>驗證 <?php echo esc_html(ucfirst(str_replace('-', ' ', $plugin))); ?> 金鑰</h2>
        <?php echo $message; ?>
        <form method="post">
            <p>
                <label for="key">請輸入您的金鑰</label><br>
                <input type="text" name="key" id="key" class="regular-text" required />
            </p>
            <p>
                <input type="submit" name="odr_auth_verify_key" class="button-primary" value="驗證金鑰" />
            </p>
        </form>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('odr_auth_verify_key', 'odr_auth_verify_key_shortcode');

// 檢查金鑰狀態並控制插件訪問
add_filter('portfolio_player_auth_check', 'odr_auth_check_key', 10, 2);
function odr_auth_check_key($default, $user_id) {
    $plugin = 'portfolio-player';
    $meta_key = 'portfolio_player_key_' . $plugin;
    $key = get_user_meta($user_id, $meta_key, true);
    if (empty($key)) {
        if (!is_page('verify-key')) {
            wp_redirect(home_url('/verify-key'));
            exit;
        }
        return false;
    }

    $last_checked = get_user_meta($user_id, $meta_key . '_checked', true);
    $cached_valid = get_user_meta($user_id, $meta_key . '_valid', true);
    if ($last_checked && (time() - $last_checked < 3600) && $cached_valid !== '') {
        return $cached_valid === 'yes';
    }

    $api_url = get_option('odr_auth_api_url', 'https://ourdaysrecords.com/boss/wp-json/odr-key/v1/check');
    $response = wp_remote_get($api_url . '?key=' . urlencode($key), array(
        'timeout' => 15,
        'sslverify' => false,
    ));

    if (is_wp_error($response)) {
        error_log("ODR Auth: API 檢查失敗，假設金鑰有效 - User ID: $user_id, Key: $key");
        return true;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $is_valid = isset($body['valid']) && $body['valid'] && isset($body['plugin']) && $body['plugin'] === $plugin;
    update_user_meta($user_id, $meta_key . '_checked', time());
    update_user_meta($user_id, $meta_key . '_valid', $is_valid ? 'yes' : 'no');

    if (!$is_valid) {
        delete_user_meta($user_id, $meta_key);
        if (!is_page('verify-key')) {
            wp_redirect(home_url('/verify-key'));
            exit;
        }
        return false;
    }
    return $is_valid;
}

// 新增 ShortCode：顯示已驗證的插件或驗證表單
function odr_auth_plugins_shortcode() {
    if (!is_user_logged_in()) {
        return '<p>請<a href="' . esc_url(wp_login_url(get_permalink())) . '">登入</a>以查看您的插件。</p>';
    }

    $user_id = get_current_user_id();
    $plugins = array(
        'portfolio-player' => array(
            'name' => 'Portfolio Player',
            'settings_url' => home_url('/artist-settings')
        )
    );

    $verified_plugins = array();
    foreach ($plugins as $plugin_slug => $plugin_info) {
        $meta_key = 'portfolio_player_key_' . $plugin_slug;
        $key = get_user_meta($user_id, $meta_key, true);
        if ($key) {
            $last_checked = get_user_meta($user_id, $meta_key . '_checked', true);
            $cached_valid = get_user_meta($user_id, $meta_key . '_valid', true);
            if ($last_checked && (time() - $last_checked < 3600) && $cached_valid === 'yes') {
                $verified_plugins[$plugin_slug] = $plugin_info;
            } else {
                $api_url = get_option('odr_auth_api_url', 'https://ourdaysrecords.com/boss/wp-json/odr-key/v1/check');
                $response = wp_remote_get($api_url . '?key=' . urlencode($key), array(
                    'timeout' => 15,
                    'sslverify' => false,
                ));
                if (!is_wp_error($response)) {
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    $is_valid = isset($body['valid']) && $body['valid'] && isset($body['plugin']) && $body['plugin'] === $plugin_slug;
                    update_user_meta($user_id, $meta_key . '_checked', time());
                    update_user_meta($user_id, $meta_key . '_valid', $is_valid ? 'yes' : 'no');
                    if ($is_valid) {
                        $verified_plugins[$plugin_slug] = $plugin_info;
                    } else {
                        delete_user_meta($user_id, $meta_key);
                    }
                }
            }
        }
    }

    ob_start();
    if (!empty($verified_plugins)) {
        ?>
        <div class="odr-auth-plugins">
            <h2>您的插件</h2>
            <ul>
                <?php foreach ($verified_plugins as $plugin_slug => $plugin_info): ?>
                    <li>
                        <a href="<?php echo esc_url($plugin_info['settings_url']); ?>">
                            <?php echo esc_html($plugin_info['name']); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    } else {
        $message = '';
        if (isset($_POST['odr_auth_verify_key'])) {
            $key = sanitize_text_field($_POST['key'] ?? '');
            if (empty($key)) {
                $message = '<p style="color: red;">請輸入金鑰。</p>';
            } else {
                $api_url = get_option('odr_auth_api_url', 'https://ourdaysrecords.com/boss/wp-json/odr-key/v1/check');
                $response = wp_remote_get($api_url . '?key=' . urlencode($key), array(
                    'timeout' => 15,
                    'sslverify' => false,
                ));

                if (is_wp_error($response)) {
                    $message = '<p style="color: red;">無法驗證金鑰：' . $response->get_error_message() . '</p>';
                } else {
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    if (isset($body['valid']) && $body['valid'] && isset($body['plugin']) && array_key_exists($body['plugin'], $plugins) && isset($body['email'])) {
                        $current_user = wp_get_current_user();
                        if ($body['email'] !== $current_user->user_email) {
                            $message = '<p style="color: red;">金鑰不匹配您的 Email，請使用購買金鑰的 Email 登入。</p>';
                        } else {
                            $meta_key = 'portfolio_player_key_' . $body['plugin'];
                            update_user_meta($user_id, $meta_key, $key);
                            update_user_meta($user_id, $meta_key . '_checked', time());
                            update_user_meta($user_id, $meta_key . '_valid', 'yes');
                            $message = '<p style="color: green;">金鑰驗證成功，已啟用！</p><script>window.location.reload();</script>';
                        }
                    } else {
                        $message = '<p style="color: red;">無效的金鑰，請檢查後重新輸入。</p>';
                    }
                }
            }
        }
        ?>
        <div class="odr-auth-plugins">
            <h2>驗證您的插件</h2>
            <p>請輸入您收到的金鑰以啟用插件。</p>
            <?php echo $message; ?>
            <form method="post">
                <p>
                    <label for="key">金鑰</label><br>
                    <input type="text" name="key" id="key" class="regular-text" required />
                </p>
                <p>
                    <input type="submit" name="odr_auth_verify_key" class="button-primary" value="驗證金鑰" />
                </p>
            </form>
        </div>
        <?php
    }
    return ob_get_clean();
}
add_shortcode('odr-auth-plugins', 'odr_auth_plugins_shortcode');

// 新增 REST API 端點檢查金鑰驗證狀態
add_action('rest_api_init', 'odr_auth_register_api');
function odr_auth_register_api() {
    register_rest_route('odr-auth/v1', '/check-verified', array(
        'methods' => 'GET',
        'callback' => 'odr_auth_check_verified',
        'permission_callback' => '__return_true',
        'args' => array(
            'key' => array(
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'plugin' => array(
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
        ),
    ));
}

function odr_auth_check_verified($request) {
    $key = $request->get_param('key');
    $plugin = $request->get_param('plugin');
    $meta_key = 'portfolio_player_key_' . $plugin;

    $users = get_users(array(
        'meta_key' => $meta_key,
        'meta_value' => $key,
        'number' => 1,
    ));

    if (!empty($users)) {
        $user_id = $users[0]->ID;
        $cached_valid = get_user_meta($user_id, $meta_key . '_valid', true);
        return array(
            'key' => $key,
            'plugin' => $plugin,
            'verified' => $cached_valid === 'yes',
            'message' => $cached_valid === 'yes' ? '已啟用' : '已失效'
        );
    }

    return array(
        'key' => $key,
        'plugin' => $plugin,
        'verified' => false,
        'message' => '未啟用'
    );
}

// 新增後台選單
add_action('admin_menu', 'odr_auth_admin_menu');
function odr_auth_admin_menu() {
    add_menu_page(
        'ODR Auth Settings',
        'ODR Auth',
        'manage_options',
        'odr-auth-settings',
        'odr_auth_settings_page',
        'dashicons-lock',
        82
    );
}

// 註冊設定
add_action('admin_init', 'odr_auth_register_settings');
function odr_auth_register_settings() {
    register_setting('odr_auth_settings_group', 'odr_auth_api_url', array(
        'type' => 'string',
        'sanitize_callback' => 'esc_url_raw',
        'default' => 'https://ourdaysrecords.com/boss/wp-json/odr-key/v1/check',
    ));
}

// 設定頁面內容（含手動刪除金鑰）
function odr_auth_settings_page() {
    $plugins = array('portfolio-player' => 'Portfolio Player');

    // 處理手動刪除金鑰
    if (isset($_POST['delete_key']) && isset($_POST['user_id']) && isset($_POST['plugin_slug'])) {
        $user_id = intval($_POST['user_id']);
        $plugin_slug = sanitize_text_field($_POST['plugin_slug']);
        $meta_key = 'portfolio_player_key_' . $plugin_slug;
        delete_user_meta($user_id, $meta_key);
        delete_user_meta($user_id, $meta_key . '_checked');
        delete_user_meta($user_id, $meta_key . '_valid');
        echo '<div class="notice notice-success is-dismissible"><p>金鑰已成功刪除。</p></div>';
    }

    ?>
    <div class="wrap">
        <h1>ODR Auth 設定</h1>
        <p>管理多個插件的金鑰驗證設定。</p>

        <form method="post" action="options.php">
            <?php
            settings_fields('odr_auth_settings_group');
            do_settings_sections('odr-auth-settings');
            ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="odr_auth_api_url">金鑰驗證 API 網址</label></th>
                    <td>
                        <input type="url" name="odr_auth_api_url" id="odr_auth_api_url" value="<?php echo esc_attr(get_option('odr_auth_api_url')); ?>" class="regular-text" />
                        <p class="description">輸入用於驗證金鑰的 API 端點。</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <h2>已驗證的金鑰列表</h2>
        <table class="widefat fixed" cellspacing="0">
            <thead>
                <tr>
                    <th>用戶 ID</th>
                    <th>用戶 Email</th>
                    <th>插件</th>
                    <th>金鑰</th>
                    <th>金鑰狀態</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $users = get_users();
                $found = false;
                $api_url = get_option('odr_auth_api_url');
                foreach ($users as $user) {
                    foreach ($plugins as $plugin_slug => $plugin_name) {
                        $meta_key = 'portfolio_player_key_' . $plugin_slug;
                        $key = get_user_meta($user->ID, $meta_key, true);
                        if ($key) {
                            $cached_valid = get_user_meta($user->ID, $meta_key . '_valid', true);
                            $status = $cached_valid === 'yes' ? '已啟用' : '已失效';
                            $found = true;
                            echo '<tr>';
                            echo '<td>' . esc_html($user->ID) . '</td>';
                            echo '<td>' . esc_html($user->user_email) . '</td>';
                            echo '<td>' . esc_html($plugin_name) . '</td>';
                            echo '<td>' . esc_html($key) . '</td>';
                            echo '<td>' . esc_html($status) . '</td>';
                            echo '<td>';
                            echo '<form method="post" style="display:inline;">';
                            echo '<input type="hidden" name="user_id" value="' . esc_attr($user->ID) . '">';
                            echo '<input type="hidden" name="plugin_slug" value="' . esc_attr($plugin_slug) . '">';
                            echo '<input type="submit" name="delete_key" value="刪除" class="button button-secondary" onclick="return confirm(\'確定要刪除此金鑰嗎？\');">';
                            echo '</form>';
                            echo '</td>';
                            echo '</tr>';
                        }
                    }
                }
                if (!$found) {
                    echo '<tr><td colspan="6">尚未有用戶驗證金鑰。</td></tr>';
                }
                ?>
            </tbody>
        </table>
    </div>
    <?php
}
