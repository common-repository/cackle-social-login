<?php
/*
Plugin Name: Cackle Social Login
Plugin URI: http://cackle.me/
Description: Cackle Social Login
Version: 1.0.3
Author: Cackle
Author URI: http://cackle.me/
License: GPL2
*/
define('CACKLE_SOCIAL_LOGIN_PLUGIN_URL', WP_CONTENT_URL . '/plugins/' . cackle_social_login_plugin_basename(__FILE__));
function cackle_social_login_plugin_basename($file) {
    $file = dirname($file);
    $file = str_replace('\\', '/', $file);
    $file = preg_replace('|/+|', '/', $file);
    $file = preg_replace('|^.*/' . PLUGINDIR . '/|', '', $file);
    if (strstr($file, '/') === false) {
        return $file;
    }
    $pieces = explode('/', $file);
    return !empty($pieces[count($pieces) - 1]) ? $pieces[count($pieces) - 1] : $pieces[count($pieces) - 2];
}

function cackle_social_login_manage() {
    include_once (dirname(__FILE__) . '/manage.php');
}
function cackle_social_login_i($text, $params = null) {
    if (!is_array($params)) {
        $params = func_get_args();
        $params = array_slice($params, 1);
    }
    return vsprintf(__($text, 'cackle'), $params);
}

class CackleSocialLogin {
    function CackleSocialLogin() {
        add_action('admin_menu', array($this, 'CackleSocialSettings'), 10);
        add_action('admin_head', array($this, 'cackle_social_login_admin_head'));
        //add_filter('simplemodal_login_form', cackle_social_login_simplemodal_login_form);
        add_action('login_form', array($this,cackle_social_login_login_form));
        add_action('register_form',array($this,cackle_social_login_login_form));
        add_action('parse_request', array($this,cackle_social_login_parse_request));
        add_action('get_avatar', array($this,cackle_get_avatar), 10, 2);

    }

    function cackle_get_avatar($avatar, $id_or_email) {
        if (is_numeric($id_or_email)) {
            $user_id = get_user_by('id', (int) $id_or_email)->ID;
        } elseif (is_object($id_or_email)) {
            if (!empty($id_or_email->user_id)) {
                $user_id = $id_or_email->user_id;
            } elseif (!empty($id_or_email->comment_author_email)) {
                $user_id = get_user_by('email', $id_or_email->comment_author_email)->ID;
            }
        } else {
            $user_id = get_user_by('email', $id_or_email)->ID;
        }
        $photo = get_user_meta($user_id, 'cackle_avatar', 1);
        if ($photo)
            return preg_replace('/src=([^\s]+)/i', 'src="' . $photo . '"', $avatar);

        return $avatar;
    }
    function get_user_by_token($token = false)
    {
        $response = false;
        $request = 'http://cackle.me/login/' . get_option('cackle_social_login_siteId') . '/getUser.json?token=' . $token;

        if (function_exists('file_get_contents') && ini_get('allow_url_fopen')){

            $response = file_get_contents($request);

        }elseif(in_array('curl', get_loaded_extensions())){

            curl_init($request);
            curl_setopt($request, CURLOPT_RETURNTRANSFER, 1);
            $response = curl_exec($request);

        } else {

            return;

        }

        return $response;

    }

    function cackle_social_login_parse_request() {

        if (isset($_POST['token'])) {

            $data = $this->get_user_by_token($_POST['token']);

            if (!$data)
                return;

            $user = json_decode($data, true);
            $user = $user['widgetUser'];
            if (isset($user['id'])) {

                $user_id = get_user_by('login', 'cackle_social_login_' . $user['provider'] . '_' . $user['id']);

                if (isset($user_id->ID)) {

                    if ($user['www'] != $user_id->data->user_url){

                        wp_update_user(array('ID'=>$user_id, 'user_url' => $user['www']));

                    }

                    $user_id = $user_id->ID;

                } else {
                    if ($user['email'] == $user_id->data->email){
                        $message = '<p class="message">' . __('Your email is already used.') . '</p>';
                        echo($message );

                    }
                    else{
                        $user_name = preg_split('/\s+/', $user['name'], -1, PREG_SPLIT_NO_EMPTY);
                        $user_id = wp_insert_user(array('user_pass' => wp_generate_password(),
                            'user_login' => 'cackle_social_login_' . $user['provider'] . '_' . $user['id'],
                            'user_url' => $user['www'],
                            'user_email' => $user['email'],
                            'first_name' => $user_name['0'],
                            'last_name' => $user_name['1'],
                            'display_name' => $user['name'],
                            'nickname' => $user['name']));
                        $i = 0;
                        $email = explode('@', $user['email']);
                    }



                }
                if (is_int($user_id)){
                    update_usermeta($user_id, 'cackle_avatar', $user['avatar']);
                    wp_set_current_user($user_id);
                    wp_set_auth_cookie($user_id);
                    $redirect_to = !empty( $_REQUEST['redirect_to'] ) ? $_REQUEST['redirect_to'] : $_REQUEST['REQUEST_URI'];
                    wp_redirect($redirect_to);
                }
                else{
                    echo "<p style='color:red'>Your email is already used </p>";

                }



            }
        }
    }


    function cackle_social_login_login_form($text){
        return str_replace('<div class="simplemodal-login-fields">', '<div class="simplemodal-login-fields">' . print_r($this->cackle_social_login_panel('')), $text);

    }

    function CackleSocialSettings() {
        add_submenu_page('plugins.php', 'Cackle Social Login', 'Cackle Social Login', 'manage_options', 'cackle-social-login', 'cackle_social_login_manage');
        //add_submenu_page('edit-comments.php', 'Cackle', 'Cackle', 'moderate_comments', 'cackle', 'cackle_manage');
    }

    function cackle_social_login_panel($id='') {
        global $current_user;
        $siteUrl = str_replace("http://","",site_url());

        if (!$current_user->ID) {
            $siteId=get_option('cackle_social_login_siteId');
            $text = <<<HTML
<div id="mc-login"></div>
<script type="text/javascript">
cackle_widget = window.cackle_widget || [];
cackle_widget.push({widget: 'Login', id: '$siteId',redirect:'$siteUrl'});
(function() {
    var mc = document.createElement('script');
    mc.type = 'text/javascript';
    mc.async = true;
    mc.src = ('https:' == document.location.protocol ? 'https' : 'http') + '://cackle.me/widget.js';
    var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(mc, s.nextSibling);
})();
</script>
<style>#reg_passmail{display:none;}</style>
HTML;
            return $text;

        }

    }

    function cackle_social_login_admin_head() {
        if (isset ($_GET ['page']) && $_GET ['page'] == 'cackle-social-login') {
            ?>

        <link rel='stylesheet'
              href='<?php echo CACKLE_SOCIAL_LOGIN_PLUGIN_URL; ?>/manage.css'
              type='text/css'/>
        <style type="text/css">
            .cackle-importing, .cackle-imported, .cackle-import-fail, .cackle-exporting, .cackle-exported, .cackle-export-fail {
                background: url(<?php echo admin_url('images/loading.gif'); ?>) left center no-repeat;
                line-height: 16px;
                padding-left: 20px;
            }

            p.status {
                padding-top: 0;
                padding-bottom: 0;
                margin: 0;
            }

            .cackle-imported, .cackle-exported {
                background: url(<?php
                    echo admin_url('images/yes.png');
                    ?>) left center no-repeat;
            }

            .cackle-import-fail, .cackle-export-fail {
                background: url(<?php
                    echo admin_url('images/no.png');
                    ?>) left center no-repeat;
            }
        </style>
        <script type="text/javascript">
            jQuery(function ($) {
                $('#cackle-tabs li').click(function () {
                    $('#cackle-tabs li.selected').removeClass('selected');
                    $(this).addClass('selected');
                    $('.cackle-main, .cackle-advanced').hide();
                    $('.' + $(this).attr('rel')).show();
                });
                if (location.href.indexOf('#adv') != -1) {
                    $('#cackle-tab-advanced').click();
                }
                <?php if (isset($_POST['site_api_key'])) { ?>
                    $('#cackle-tab-advanced').click()
                    <?php }?>
                cackle_fire_export();
                cackle_fire_import();
            });
            cackle_fire_export = function () {
                var $ = jQuery;
                $('#cackle_export a.button, #cackle_export_retry').unbind().click(function () {
                    $('#cackle_export').html('<p class="status"></p>');
                    $('#cackle_export .status').removeClass('cackle-export-fail').addClass('cackle-exporting').html('Processing...');
                    cackle_export_comments();
                    return false;
                });
            }
            cackle_export_comments = function () {
                var $ = jQuery;
                var status = $('#cackle_export .status');
                var export_info = (status.attr('rel') || '0|' + (new Date().getTime() / 1000)).split('|');
                $.get(
                        '<?php echo admin_url('index.php'); ?>',
                        {
                            cf_action:'export_comments',
                            post_id:export_info[0],
                            timestamp:export_info[1]
                        },
                        function (response) {
                            switch (response.result) {
                                case 'success':
                                    status.html(response.msg).attr('rel', response.post_id + '|' + response.timestamp);
                                    switch (response.status) {
                                        case 'partial':
                                            cackle_export_comments();
                                            break;
                                        case 'complete':
                                            status.removeClass('cackle-exporting').addClass('cackle-exported');
                                            break;
                                    }
                                    break;
                                case 'fail':
                                    status.parent().html(response.msg);
                                    cackle_fire_export();
                                    break;
                            }
                        },
                        'json'
                );
            }
            cackle_fire_import = function () {
                var $ = jQuery;
                $('#cackle_import a.button, #cackle_import_retry').unbind().click(function () {
                    var wipe = $('#cackle_import_wipe').is(':checked');
                    $('#cackle_import').html('<p class="status"></p>');
                    $('#cackle_import .status').removeClass('cackle-import-fail').addClass('cackle-importing').html('Processing...');
                    cackle_import_comments(wipe);
                    return false;
                });
            }
            cackle_import_comments = function (wipe) {
                var $ = jQuery;
                var status = $('#cackle_import .status');
                var last_comment_id = status.attr('rel') || '0';
                $.get(
                        '<?php echo admin_url('index.php'); ?>',
                        {
                            cf_action:'import_comments',
                            last_comment_id:last_comment_id,
                            wipe:(wipe ? 1 : 0)
                        },
                        function (response) {
                            switch (response.result) {
                                case 'success':
                                    status.html(response.msg).attr('rel', response.last_comment_id);
                                    switch (response.status) {
                                        case 'partial':
                                            cackle_import_comments(false);
                                            break;
                                        case 'complete':
                                            status.removeClass('cackle-importing').addClass('cackle-imported');
                                            break;
                                    }
                                    break;
                                case 'fail':
                                    status.parent().html(response.msg);
                                    cackle_fire_import();
                                    break;
                            }
                        },
                        'json'
                );
            }
        </script>
        <?php
        }
    }

}

function cackle_social_login_init() {
    $a = new CackleSocialLogin();
}

add_action('init', 'cackle_social_login_init');