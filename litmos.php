<?php
/**
 * Plugin Name: Litmos
 * Plugin URI: http://www.litmos.com
 * Description: Import course content from Litmos Learning Platform by CallidusCloud
 * Version: 1.0.0
 * Author: CallidusCloud
 * License: GPLv2 or later
 */

define('LITMOS_BASE_URI', 'https://api.litmos.com/v1.svc');
define('LITMOS_POST_TYPE', 'litmos_course');

register_activation_hook(__FILE__, 'litmos_activation');
register_deactivation_hook(__FILE__, 'litmos_deactivation');

add_action('init', 'litmos_create_post_type');
add_action('admin_menu', 'litmos_add_pages');
add_action('litmos_hourly_event_hook', 'litmos_do_this_hourly');
add_action('template_redirect', 'litmos_template_redirect_intercept');

add_filter('user_contactmethods', 'litmos_modify_contact_methods');

function litmos_create_post_type()
{
    register_post_type(LITMOS_POST_TYPE,
        array(
            'labels' => array(
                'name' => __('Litmos Courses'),
                'singular_name' => __('Litmos Course')
            ),
            'public' => true,
            'has_archive' => true,
            'supports' => array('title', 'editor', 'custom-fields'),
        )
    );
}

function litmos_add_pages()
{
    add_options_page(__('Litmos', 'menu-litmos'), __('Litmos', 'menu-litmos'), 'manage_options', 'litmossettings', 'litmos_settings_page');
}

function litmos_activation()
{
    wp_schedule_event(time(), 'hourly', 'litmos_hourly_event_hook');
}

function litmos_deactivation()
{
    wp_clear_scheduled_hook('litmos_hourly_event_hook');
}

function litmos_do_this_hourly()
{
    litmos_import_courses();
}

function litmos_import_courses()
{
    $args = array(
        'post_type' => LITMOS_POST_TYPE,
    );
    $posts = get_posts($args);
    $response = litmos_api_request('/courses');
    if (is_array($response) && isset($response['response']['code']) && $response['response']['code'] == 200 && isset($response['body'])) {
        $response_data = simplexml_load_string($response['body']);
        if (is_object($response_data)) {
            foreach ($response_data->Course as $course) {
                $existing_course = litmos_course_exist($course->OriginalId, $posts);
                litmos_create_course($course, $existing_course);
            }
        }
    }
}

function litmos_create_course($course_xml, $post = false)
{
    $author_id = 1;
    $title = $course_xml->Name;
    $slug = preg_replace("/[^A-Za-z0-9 ]/", "", $title);
    $slug = preg_replace("/\s/", "-", $slug);

    if ($post == false) {
        $post_id = wp_insert_post(
            array(
                'comment_status' => 'closed',
                'ping_status' => 'closed',
                'post_author' => $author_id,
                'post_name' => $slug,
                'post_title' => $title,
                'post_status' => 'publish',
                'post_type' => LITMOS_POST_TYPE,
                'post_content' => $course_xml->Description,
            )
        );
        $post = get_post($post_id);
    } else {
        $post->post_content = $course_xml->Description;
        $post->post_title = $title;
        wp_update_post($post);
    }

    if (is_object($post)) {
        $litmos_id = (string)$course_xml->Id;
        $litmos_code = (string)$course_xml->Code;
        $litmos_active = (string)$course_xml->Active;
        $litmos_for_sale = (string)$course_xml->ForSale;
        $litmos_original_id = (string)$course_xml->OriginalId;
        $litmos_ecommerce_short_desc = (string)$course_xml->EcommerceShortDescription;
        $litmos_ecommerce_long_desc = (string)$course_xml->EcommerceLongDescription;
        $litmos_price = (string)$course_xml->Price;

        add_post_meta($post->ID, 'litmos_id', $litmos_id, true) || update_post_meta($post->ID, 'litmos_id', $litmos_id);
        add_post_meta($post->ID, 'litmos_code', $litmos_code, true) || update_post_meta($post->ID, 'litmos_code', $litmos_code);
        add_post_meta($post->ID, 'litmos_active', $litmos_active, true) || update_post_meta($post->ID, 'litmos_active', $litmos_active);
        add_post_meta($post->ID, 'litmos_for_sale', $litmos_for_sale, true) || update_post_meta($post->ID, 'litmos_for_sale', $litmos_for_sale);
        add_post_meta($post->ID, 'litmos_original_id', $litmos_original_id, true) || update_post_meta($post->ID, 'litmos_original_id', $litmos_original_id);
        add_post_meta($post->ID, 'litmos_ecommerce_short_desc', $litmos_ecommerce_short_desc, true) || update_post_meta($post->ID, 'litmos_ecommerce_short_desc', $litmos_ecommerce_short_desc);
        add_post_meta($post->ID, 'litmos_ecommerce_long_desc', $litmos_ecommerce_long_desc, true) || update_post_meta($post->ID, 'litmos_ecommerce_long_desc', $litmos_ecommerce_long_desc);
        add_post_meta($post->ID, 'litmos_price', $litmos_price, true) || update_post_meta($post->ID, 'litmos_price', $litmos_price);
    }
}

function litmos_api_request($uri, $query = '')
{
    $litmos_source = litmos_get_source();
    $litmos_api_key = litmos_get_api_key();
    if (!empty($litmos_source) && !empty($litmos_api_key)) {
        $uri = LITMOS_BASE_URI . $uri . '?apikey=' . $litmos_api_key . '&source=' . urlencode($litmos_source);
        if (!empty($query)) {
            $uri .= '&' . $query;
        }

        return wp_remote_get($uri);
    }

    return false;
}

function litmos_modify_contact_methods($profile_fields)
{
    $profile_fields['litmos_username'] = 'Litmos Username';
    return $profile_fields;
}

function litmos_get_source()
{
    return get_option('litmos_source');
}

function litmos_get_api_key()
{
    return get_option('litmos_api_key');
}

function litmos_get_litmos_username()
{
    $user_ID = get_current_user_id();

    return get_the_author_meta('litmos_username', $user_ID);
}

function litmos_get_original_id($post_id)
{
    $course_meta = get_post_custom_values('litmos_original_id', $post_id);
    if (is_array($course_meta) && isset($course_meta[0])) {
        return $course_meta[0];
    }

    return false;
}

function litmos_course_exist($courseId, $posts = array())
{
    if (empty($posts)) {
        $args = array(
            'post_type' => LITMOS_POST_TYPE,
        );
        $posts = get_posts($args);
    }
    foreach ($posts as $post) {
        $originalCourseId = litmos_get_original_id($post->ID);
        if ($courseId == $originalCourseId) {
            return $post;
        }
    }

    return false;
}

function litmos_course_login($courseId)
{
    $litmos_email = litmos_get_litmos_username();
    if (isset($litmos_email) && !empty($litmos_email)) {
        $litmos_user_search_response = litmos_api_request('/users', 'search=' . $litmos_email);
        if (is_array($litmos_user_search_response) && isset($litmos_user_search_response['response']['code']) && $litmos_user_search_response['response']['code'] == 200 && isset($litmos_user_search_response['body'])) {
            $litmos_user_search_response = simplexml_load_string($litmos_user_search_response['body']);
            $litmos_user_id = (string)$litmos_user_search_response->User->Id;
            $litmos_user_response = litmos_api_request('/users/' . $litmos_user_id);
            if (is_array($litmos_user_response) && isset($litmos_user_response['response']['code']) && $litmos_user_response['response']['code'] == 200 && isset($litmos_user_response['body'])) {
                $litmos_user_response = simplexml_load_string($litmos_user_response['body']);
                $litmos_login = $litmos_user_response->LoginKey . '&c=' . $courseId;

                return $litmos_login;
            } else {
                throw new Exception('We encountered an error: unable to load your Litmos user on the Litmos Platform. Please try again and if you encounter problems contact your site administrator.');
            }
        } else {
            throw new Exception('We encountered an error: unable to locate your Litmos username on the Litmos Platform. Please ensure your Litmos username is correct. If you still encounter problems, please contact your site administrator.');
        }
    } else {
        throw new Exception('We encountered an error: unable to find Litmos username. Please edit your profile and add your Litmos username to your profile.');
    }

    return false;
}

function litmos_template_redirect_intercept()
{
    global $wp_query;
    if (is_object($wp_query) && isset($wp_query->query['post_type']) && $wp_query->query['post_type'] == LITMOS_POST_TYPE && isset($_GET['login']) && isset($wp_query->post)) {
        $course_meta = litmos_get_original_id($wp_query->post->ID);
        if ($course_meta) {
            try {
                $litmos_sso = litmos_course_login($course_meta);
                header('Location: ' . $litmos_sso);
                exit;
            } catch (Exception $e) {
                header('Location: ' . $wp_query->post->guid . '&errMsg=' . $e->getMessage());
                exit;
            }
        }
    }
}

function litmos_settings_page()
{

    if (isset($_GET['litmos_sync'])) {
        litmos_import_courses();
    }
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    $opt_name = 'litmos_api_key';
    $data_field_name = 'litmos_api_key';
    $source_opt_name = 'litmos_source';
    $source_field_name = 'litmos_source';
    $opt_val = get_option($opt_name);
    $source_opt_val = get_option($source_opt_name);
    if (!empty($_POST)) {
        $opt_val = $_POST[$data_field_name];
        update_option($opt_name, $opt_val);
        $source_opt_val = $_POST[$source_field_name];
        update_option($source_opt_name, $source_opt_val);

        ?>
        <div class="updated"><p><strong><?php _e('settings saved.', 'menu-litmos'); ?></strong></p></div>
    <?php
    }
    echo '<div class="wrap">';
    echo "<h2>" . __('Litmos Plugin Settings', 'menu-limtos') . "</h2>";
    ?>
<form name="form1" method="post" action="">
<p><?php _e("Litmos Source:", 'menu-limtos'); ?>
<input type="text" name="<?php echo $source_field_name; ?>" value="<?php echo $source_opt_val; ?>" size="20">
</p>
<p><?php _e("Litmos API Key:", 'menu-limtos'); ?>
<input type="text" name="<?php echo $data_field_name; ?>" value="<?php echo $opt_val; ?>" size="20">
</p>
<hr />
<p class="submit">
<input type="submit" name="Submit" class="button-primary" value="<?php esc_attr_e('Save Changes') ?>" />
</p>
</form>
<p><a href="<?php echo $_SERVER['REQUEST_URI'] . '&litmos_sync=1'; ?>">Import Litmos Courses Now</a></p>
</div>
<?php
}