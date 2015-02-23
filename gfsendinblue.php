<?php
/**
Plugin Name: Gravity Forms SendinBlue Add-On
Plugin URI: https://www.sendinblue.com/?r=wporg
Description: Integrates Gravity Forms with SendinBlue allowing form submissions to be automatically sent to your SendinBlue account.
Version: 1.0.1
Author: SendinBlue
Author URI: https://www.sendinblue.com/?r=wporg
License: GPLv2 or later
*/
/**
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.
This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

if(!class_exists('Mailin')) {
    require_once('inc/mailin.php');
}

/**
 * Application entry point. Contains plugin startup class that loads on <i> gfsendinblue_init </i> action.
 * @package GFSIB
 */
add_action('init',  array('GFSIB_Manager', 'init'));
register_activation_hook( __FILE__, array("GFSIB_Manager", "add_permissions"));

if (!class_exists('GFSIB_Manager')) {
    class GFSIB_Manager
    {
        private static $name = "Gravity Forms SendinBlue Add-On";
        private static $path = "gravity-forms-sendinblue/gfsendinblue.php";
        private static $url = "http://www.gravityforms.com";
        private static $slug = "gravity-forms-sendinblue";
        private static $version = "1.0.0";
        private static $min_gravityforms_version = "1.3.9";

        /**
         * Access key
         */
        public static $access_key;

        /**
         * secret key
         */
        public static $secret_key;

        /**
         * Class constructor
         * Sets plugin url and directory and adds hooks to <i>init</i>. <i>admin_menu</i>
         */
        function init()
        {
            global $pagenow;
            // get basic info

            if ($pagenow === 'plugins.php') {
                add_action("admin_notices", array('GFSIB_Manager', 'is_gravity_forms_installed'), 10);
            }

            if (self::is_gravity_forms_installed(false, false) === 0) {
                add_action('after_plugin_row_' . self::$path, array('GFSIB_Manager', 'plugin_row'));
                return;
            }

            if (defined('RG_CURRENT_PAGE') && RG_CURRENT_PAGE == "plugins.php") {
                //loading translations
                load_plugin_textdomain('gravity-forms-sendinblue', FALSE, '/gravity-forms-sendinblue/lang');
            }


            //Load data class.
            require_once(self::get_base_path() . "/inc/data.php");
            //self::add_permissions();
            if (is_admin()) {
                if (!class_exists('RGForms')) {
                  return;
                }
                //loading translations
                load_plugin_textdomain('gravity-forms-sendinblue', FALSE, '/gravity-forms-sendinblue/lang');

                //creates a new Settings page on Gravity Forms' settings screen
                if (self::has_access("gravityforms_sendinblue")) {
                    RGForms::add_settings_page("SendinBlue", array("GFSIB_Manager", "settings_page"), self::get_base_path() . "/images/logo.png");
                }
            }

            //integrating with Members plugin
            if (function_exists('members_get_capabilities'))
                add_filter('members_get_capabilities', array("GFSIB_Manager", "members_get_capabilities"));

            //creates the subnav left menu
            add_filter("gform_addon_navigation", array('GFSIB_Manager', 'create_menu'));

            if (self::is_sendinblue_page()) {

                //loading Gravity Forms tooltips
                require_once(GFCommon::get_base_path() . "/tooltips.php");

                //enqueueing sack for AJAX requests
                wp_enqueue_script("sack");

                add_filter('gform_tooltips', array('GFSIB_Manager', 'tooltips'));

                //loading data lib
                require_once(self::get_base_path() . "/inc/data.php");

                //runs the setup when version changes
                self::setup();
            } else if (in_array(RG_CURRENT_PAGE, array("admin-ajax.php"))) {

                //loading data class
                require_once(self::get_base_path() . "/inc/data.php");

                add_action('wp_ajax_rg_update_feed_active', array('GFSIB_Manager', 'update_feed_active'));
                add_action('wp_ajax_gf_select_sendinblue_form', array('GFSIB_Manager', 'select_sendinblue_form'));

            } else {
                //handling post submission.
                add_action("gform_post_submission", array('GFSIB_Manager', 'export'), 10, 2);
            }
        }

        public static function is_gravity_forms_installed($asd = '', $echo = true)
        {
            global $pagenow, $page;
            $message = '';

            $installed = 0;
            $name = self::$name;
            if (!class_exists('RGForms')) {
                if (file_exists(WP_PLUGIN_DIR . '/gravityforms/gravityforms.php')) {
                    $installed = 1;
                    $message .= __(sprintf('%sGravity Forms is installed but not active. %sActivate Gravity Forms%s to use the %s plugin.%s', '<p>', '<strong><a href="' . wp_nonce_url(admin_url('plugins.php?action=activate&plugin=gravityforms/gravityforms.php'), 'activate-plugin_gravityforms/gravityforms.php') . '">', '</a></strong>', $name, '</p>'), 'gravity-forms-salesforce');
                } else {
                    $message .= <<<EOD
<p><a href="http://katz.si/gravityforms?con=banner" title="Gravity Forms Contact Form Plugin for WordPress">Gravity Forms Plugin for WordPress</a></p>
		<h3><a href="http://katz.si/gravityforms" target="_blank">Gravity Forms</a> is required for the $name</h3>
		<p>You do not have the Gravity Forms plugin installed. <a href="http://katz.si/gravityforms">Get Gravity Forms</a> today.</p>
EOD;
                }

                if (!empty($message) && $echo) {
                    echo '<div id="message" class="updated">' . $message . '</div>';
                }
            } else {
                return true;
            }
            return $installed;
        }

        public static function plugin_row()
        {
            if (!self::is_gravityforms_supported()) {
                $message = sprintf(__("%sGravity Forms%s is required. %sPurchase it today!%s"), "<a href='http://katz.si/gravityforms'>", "</a>", "<a href='http://katz.si/gravityforms'>", "</a>");
                self::display_plugin_message($message, true);
            }
        }

        public static function display_plugin_message($message, $is_error = false)
        {
            $style = '';
            if ($is_error)
                $style = 'style="background-color: #ffebe8;"';

            echo '</tr><tr class="plugin-update-tr"><td colspan="5" class="plugin-update"><div class="update-message" ' . $style . '>' . $message . '</div></td>';
        }

        public static function update_feed_active()
        {
            check_ajax_referer('rg_update_feed_active', 'rg_update_feed_active');
            $id = $_POST["feed_id"];
            $feed = GFSendinBlueData::get_feed($id);
            GFSendinBlueData::update_feed($id, $feed["form_id"], $_POST["is_active"], $feed["meta"]);
        }

        private static function get_key()
        {
            if (self::is_gravityforms_supported())
                return GFCommon::get_key();
            else
                return "";
        }

        /**
         *
         */
        public static function settings_page()
        {

            if (!empty($_POST["uninstall"])) {
                check_admin_referer("uninstall", "gf_sendinblue_uninstall");
                self::uninstall();

                ?>
                <div class="updated fade"
                     style="padding:20px;"><?php _e(sprintf("Gravity Forms SendinBlue Add-On has been successfully uninstalled. It can be re-activated from the %splugins page%s.", "<a href='plugins.php'>", "</a>"), "gravity-forms-sendinblue") ?></div>
                <?php
                return;
            } else if (!empty($_POST["gf_sendinblue_submit"])) {
                check_admin_referer("update", "gf_sendinblue_update");
                $settings = array("username" => stripslashes($_POST["gf_sendinblue_username"]));
                update_option("gf_sendinblue_settings", $settings);
            } else {
                $settings = get_option("gf_sendinblue_settings");
            }

            $feedback_image = "";
            //feedback for username/password
            if (!empty($settings["username"])) {

                if (isset($_POST["gf_sendinblue_submit"])) {
                    $is_valid = self::is_valid_login($settings["username"]);
                    $text = $is_valid ? 'Settings Saved: Success' : 'Settings Saved: Error';
                    //do_action( 'presstrends_event_gfcc', $text);
                } else {
                    $is_valid = get_option('gravity_forms_sb_valid_api');
                }

                if ($is_valid) {
                    $message = sprintf(__("Valid Access Key. Now go %sconfigure form integration with SendinBlue%s!", "gravity-forms-sendinblue"), '<a href="' . admin_url('admin.php?page=gf_sendinblue') . '">', '</a>');
                    $class = 'updated notice';
                    $icon = self::get_base_url() . "/images/tick.png";
                } else {
                    $message = __("Invalid Access Key. Please try another combination. Please note: spaces in your username are not allowed. You can change your Access Key in the My Account link when you are logged into your account, and this may remedy the problem.", "gravity-forms-sendinblue");
                    $class = 'error notice';
                    $icon = self::get_base_url() . "/images/stop.png";
                }
                $feedback_image = "<img src='{$icon}' />";
            }

            if ($message) {
                $message = str_replace('Api', 'API', $message);
                ?>
                <div id="message" class="<?php echo $class ?>"><?php echo wpautop($message); ?></div>
            <?php
            }

            ?>
            <style>
                .valid_credentials {
                    color: green;
                }

                .invalid_credentials {
                    color: red;
                }
            </style>

            <form method="post" action="">
                <?php wp_nonce_field("update", "gf_sendinblue_update") ?>
                <a href='https://www.sendinblue.com/users/signup' target='_blank'><img
                        alt="<?php _e("SendinBlue", "gravity-forms-sendinblue") ?>"
                        style="display:block; margin-right:10px;width:200px;margin-bottom: 5px;"
                        src="<?php echo self::get_base_url() ?>/images/logo.png"/></a>

                <h2><?php _e("SendinBlue Account Information", "gravity-forms-sendinblue") ?></h2>

                <h3><?php printf(__("If you don't have a SendinBlue account, you can %ssign up for one here%s.", 'gravity-forms-sendinblue'), "<a href='https://www.sendinblue.com/users/signup' target='_blank'>", "</a>"); ?></h3>

                <p style="text-align: left; font-size:1.2em; line-height:1.4">
                    <?php _e("SendinBlue makes it easy to send email newsletters to your customers, manage your subscriber lists, and track campaign performance. Use Gravity Forms to collect customer information and automatically add them to your SendinBlue subscriber list.", "gravity-forms-sendinblue"); ?>
                </p>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label
                                for="gf_sendinblue_username"><?php _e("SendinBlue API Access Key", "gravity-forms-sendinblue"); ?></label>
                        </th>
                        <td>
                            <input type="text" id="gf_sendinblue_username" name="gf_sendinblue_username"
                                   value="<?php echo esc_attr($settings["username"]) ?>" size="50"/>
                            <?php echo $feedback_image; ?>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2"><input type="submit" name="gf_sendinblue_submit" class="button-primary"
                                               value="<?php _e("Save Settings", "gravity-forms-sendinblue"); ?>"/></td>
                    </tr>
                </table>
            </form>

            <form action="" method="post">
                <?php wp_nonce_field("uninstall", "gf_sendinblue_uninstall") ?>
                <?php if (GFCommon::current_user_can_any("gravityforms_sendinblue_uninstall")) { ?>
                    <div class="hr-divider"></div>

                    <h3><?php _e("Uninstall SendinBlue Add-On", "gravity-forms-sendinblue") ?></h3>
                    <div
                        class="delete-alert"><?php _e("Warning! This operation deletes ALL SendinBlue Feeds.", "gravity-forms-sendinblue") ?>
                        <?php
                        $uninstall_button = '<input type="submit" name="uninstall" value="' . __("Uninstall SendinBlue Add-On", "gravity-forms-sendinblue") . '" class="button" onclick="return confirm(\'' . __("Warning! ALL SendinBlue Feeds will be deleted. This cannot be undone. \'OK\' to delete, \'Cancel\' to stop", "gravity-forms-sendinblue") . '\');"/>';
                        echo apply_filters("gform_sendinblue_uninstall_button", $uninstall_button);
                        ?>
                    </div>
                <?php } ?>
            </form>
        <?php
        }

        //Adds feed tooltips to the list of tooltips
        public static function tooltips($tooltips)
        {
            $sendinblue_tooltips = array(
                "sendinblue_contact_list" => "<h6>" . __("SendinBlue List", "gravity-forms-sendinblue") . "</h6>" . __("Select the SendinBlue list you would like to add your contacts to.", "gravity-forms-sendinblue"),
                "sendinblue_gravity_form" => "<h6>" . __("Gravity Form", "gravity-forms-sendinblue") . "</h6>" . __("Select the Gravity Form you would like to integrate with SendinBlue. Contacts generated by this form will be automatically added to your SendinBlue account.", "gravity-forms-sendinblue"),
                "sendinblue_map_fields" => "<h6>" . __("Map Fields", "gravity-forms-sendinblue") . "</h6>" . __("Associate your SendinBlue merge variables to the appropriate Gravity Form fields by selecting.", "gravity-forms-sendinblue"),
            );
            return array_merge($tooltips, $sendinblue_tooltips);
        }

        //Returns true if the current page is an Feed pages. Returns false if not
        private static function is_sendinblue_page()
        {
            global $plugin_page, $pagenow;

            return ($pagenow === 'admin.php' && isset($_GET['page']) && $_GET['page'] === 'gf_sendinblue');
        }

        protected static function has_access($required_permission)
        {
            $has_members_plugin = function_exists('members_get_capabilities');
            $has_access = $has_members_plugin ? current_user_can($required_permission) : current_user_can("level_7");
            if ($has_access)
                return $has_members_plugin ? $required_permission : "level_7";
            else
                return false;
        }

        /**
         * Returns the url of the plugin's root folder
         * @return string
         */
        static protected function get_base_url()
        {
            return plugins_url(null, __FILE__);
        }

        static public function get_file()
        {
            return __FILE__;
        }

        //Returns the physical path of the plugin's root folder
        static public function get_base_path()
        {
            $folder = basename(dirname(__FILE__));
            return WP_PLUGIN_DIR . "/" . $folder;
        }

        private static function is_gravityforms_supported()
        {
            if (class_exists("GFCommon")) {
                $is_correct_version = version_compare(GFCommon::$version, self::$min_gravityforms_version, ">=");
                return $is_correct_version;
            } else {
                return false;
            }
        }

        //Creates SendinBlue left nav menu under Forms
        public static function create_menu($menus)
        {

            // Adding submenu if user has access
            $permission = self::has_access("gravityforms_sendinblue");
            if (!empty($permission))
                $menus[] = array("name" => "gf_sendinblue", "label" => __("SendinBlue", "gravity-forms-sendinblue"), "callback" => array("GFSIB_Manager", "sendinblue_page"), "permission" => $permission);

            return $menus;
        }

        public static function sendinblue_page()
        {
            $view = isset($_GET['view']) ? $_GET['view'] : '';
            if ($view == 'edit')
                self::edit_page($_GET['id']);
            else
                self::list_page();
        }

        //Creates or updates database tables. Will only run when version changes
        private static function setup()
        {
            if (get_option("gf_sendinblue_version") != self::$version)
                GFSendinBlueData::update_table();

            update_option("gf_sendinblue_version", self::$version);
        }


        //Displays the constantcontact feeds list page
        private static function list_page()
        {
            if (!self::is_gravityforms_supported()) {
                die(__(sprintf("SendinBlue Add-On requires Gravity Forms %s. Upgrade automatically on the %sPlugin page%s.", self::$min_gravityforms_version, "<a href='plugins.php'>", "</a>"), "gravity-forms-sendinblue"));
            }

            if (!empty($_POST["action"]) && $_POST["action"] === "delete") {
                check_admin_referer("list_action", "gf_sendinblue_list");

                $id = absint($_POST["action_argument"]);
                GFSendinBlueData::delete_feed($id);
                ?>
                <div class="updated fade"
                     style="padding:6px"><?php _e("Feed deleted.", "gravity-forms-sendinblue") ?></div>
            <?php
            } else if (!empty($_POST["bulk_action"])) {
                check_admin_referer("list_action", "gf_sendinblue_list");
                $selected_feeds = $_POST["feed"];
                if (is_array($selected_feeds)) {
                    foreach ($selected_feeds as $feed_id)
                        GFSendinBlueData::delete_feed($feed_id);
                }
                ?>
                <div class="updated fade"
                     style="padding:6px"><?php _e("Feeds deleted.", "gravity-forms-sendinblue") ?></div>
            <?php
            }

            ?>
            <div class="wrap">
                <h2 style="line-height:71px;"><a href='https://www.sendinblue.com/users/signup' target='_blank'><img
                            alt="<?php _e("SendinBlue", "gravity-forms-sendinblue") ?>"
                            style="display:block; margin-right:10px;width:200px;margin-bottom: 5px;"
                            src="<?php echo self::get_base_url() ?>/images/logo.png"/></a><?php _e("SendinBlue Feeds", "gravity-forms-sendinblue") ?>
                    <a class="add-new-h2"
                       href="admin.php?page=gf_sendinblue&view=edit&id=0"><?php _e("Add New", "gravity-forms-sendinblue") ?></a>
                </h2>

                <ul class="subsubsub">
                    <li>
                        <a href="<?php echo admin_url('admin.php?page=gf_settings&addon=SendinBlue'); ?>"><?php _e('SendinBlue Settings', 'gravity-forms-sendinblue'); ?></a>
                        |
                    </li>
                    <li><a href="<?php echo admin_url('admin.php?page=gf_sendinblue'); ?>"
                           class="current"><?php _e('SendinBlue Feeds', 'gravity-forms-sendinblue'); ?></a></li>
                </ul>

                <?php
                //ensures valid credentials were entered in the settings page
                if (!get_option('gravity_forms_sb_valid_api')) {
                    _e('<div class="updated" id="message"><p>' . sprintf("To get started, please configure your %sSendinBlue Settings%s.", '<a href="admin.php?page=gf_settings&addon=SendinBlue">', "</a></p></div>"), "gravity-forms-sendinblue");
                    return;
                }
                ?>

                <form id="feed_form" method="post">
                    <?php wp_nonce_field('list_action', 'gf_sendinblue_list') ?>
                    <input type="hidden" id="action" name="action"/>
                    <input type="hidden" id="action_argument" name="action_argument"/>

                    <div class="tablenav">
                        <div class="alignleft actions" style="padding:8px 0 7px; 0">
                            <label class="hidden"
                                   for="bulk_action"><?php _e("Bulk action", "gravity-forms-sendinblue") ?></label>
                            <select name="bulk_action" id="bulk_action">
                                <option value=''> <?php _e("Bulk action", "gravity-forms-sendinblue") ?> </option>
                                <option value='delete'><?php _e("Delete", "gravity-forms-sendinblue") ?></option>
                            </select>
                            <?php
                            echo '<input type="submit" class="button" value="' . __("Apply", "gravity-forms-sendinblue") . '" onclick="if( jQuery(\'#bulk_action\').val() == \'delete\' && !confirm(\'' . __("Delete selected feeds? ", "gravity-forms-sendinblue") . __("\'Cancel\' to stop, \'OK\' to delete.", "gravity-forms-sendinblue") . '\')) { return false; } return true;"/>';
                            ?>
                        </div>
                    </div>
                    <table class="widefat fixed" cellspacing="0">
                        <thead>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input
                                    type="checkbox"/></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravity-forms-sendinblue") ?></th>
                            <th scope="col"
                                class="manage-column"><?php _e("SendinBlue List", "gravity-forms-sendinblue") ?></th>
                        </tr>
                        </thead>

                        <tfoot>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input
                                    type="checkbox"/></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravity-forms-sendinblue") ?></th>
                            <th scope="col"
                                class="manage-column"><?php _e("SendinBlue List", "gravity-forms-sendinblue") ?></th>
                        </tr>
                        </tfoot>

                        <tbody class="list:user user-list">
                        <?php

                        $settings = GFSendinBlueData::get_feeds();
                        if (is_array($settings) && sizeof($settings) > 0) {
                            foreach ($settings as $setting) {
                                ?>
                                <tr class='author-self status-inherit' valign="top">
                                    <th scope="row" class="check-column"><input type="checkbox" name="feed[]"
                                                                                value="<?php echo $setting["id"] ?>"/>
                                    </th>
                                    <td><img
                                            src="<?php echo self::get_base_url() ?>/images/active<?php echo intval($setting["is_active"]) ?>.png"
                                            alt="<?php echo $setting["is_active"] ? __("Active", "gravity-forms-sendinblue") : __("Inactive", "gravity-forms-sendinblue"); ?>"
                                            title="<?php echo $setting["is_active"] ? __("Active", "gravity-forms-sendinblue") : __("Inactive", "gravity-forms-sendinblue"); ?>"
                                            onclick="ToggleActive(this, <?php echo $setting['id'] ?>); "/></td>
                                    <td class="column-title">
                                        <a href="admin.php?page=gf_sendinblue&view=edit&id=<?php echo $setting["id"] ?>"
                                           title="<?php _e("Edit", "gravity-forms-sendinblue") ?>"><?php echo $setting["form_title"] ?></a>

                                        <div class="row-actions">
                                            <span class="edit">
                                            <a title="Edit this setting"
                                               href="admin.php?page=gf_sendinblue&view=edit&id=<?php echo $setting["id"] ?>"
                                               title="<?php _e("Edit", "gravity-forms-sendinblue") ?>"><?php _e("Edit", "gravity-forms-sendinblue") ?></a>
                                            |
                                            </span>

                                            <span class="edit">
                                            <a title="<?php _e("Delete", "gravity-forms-sendinblue") ?>"
                                               href="javascript: if(confirm('<?php _e("Delete this feed? ", "gravity-forms-sendinblue") ?> <?php _e("\'Cancel\' to stop, \'OK\' to delete.", "gravity-forms-sendinblue") ?>')){ DeleteSetting(<?php echo $setting["id"] ?>);}"><?php _e("Delete", "gravity-forms-sendinblue") ?></a>

                                            </span>
                                        </div>
                                    </td>
                                    <td class="column-date"><?php echo $setting["meta"]["contact_list_name"] ?></td>
                                </tr>
                            <?php
                            }
                        } else if (get_option('gravity_forms_sb_valid_api')) {
                            ?>
                            <tr>
                                <td colspan="4" style="padding:20px;">
                                    <?php _e(sprintf("You don't have any SendinBlue feeds configured. Let's go %screate one%s!", '<a href="admin.php?page=gf_sendinblue&view=edit&id=0">', "</a>"), "gravity-forms-sendinblue"); ?>
                                </td>
                            </tr>
                        <?php
                        } else {
                            ?>
                            <tr>
                                <td colspan="4" style="padding:20px;">
                                    <?php _e(sprintf("To get started, please configure your %sSendinBlue Settings%s.", '<a href="admin.php?page=gf_settings&addon=SendinBlue">', "</a>"), "gravity-forms-sendinblue"); ?>
                                </td>
                            </tr>
                        <?php
                        }
                        ?>
                        </tbody>
                    </table>
                </form>
            </div>
            <script type="text/javascript">
                function DeleteSetting(id) {
                    jQuery("#action_argument").val(id);
                    jQuery("#action").val("delete");
                    jQuery("#feed_form")[0].submit();
                }
                function ToggleActive(img, feed_id) {
                    var is_active = img.src.indexOf("active1.png") >= 0
                    if (is_active) {
                        img.src = img.src.replace("active1.png", "active0.png");
                        jQuery(img).attr('title', '<?php _e("Inactive", "gravity-forms-sendinblue") ?>').attr('alt', '<?php _e("Inactive", "gravity-forms-sendinblue") ?>');
                    }
                    else {
                        img.src = img.src.replace("active0.png", "active1.png");
                        jQuery(img).attr('title', '<?php _e("Active", "gravity-forms-sendinblue") ?>').attr('alt', '<?php _e("Active", "gravity-forms-sendinblue") ?>');
                    }

                    var mysack = new sack("<?php echo admin_url("admin-ajax.php")?>");
                    mysack.execute = 1;
                    mysack.method = 'POST';
                    mysack.setVar("action", "rg_update_feed_active");
                    mysack.setVar("rg_update_feed_active", "<?php echo wp_create_nonce("rg_update_feed_active") ?>");
                    mysack.setVar("feed_id", feed_id);
                    mysack.setVar("is_active", is_active ? 0 : 1);
                    mysack.encVar("cookie", document.cookie, false);
                    mysack.onError = function () {
                        alert('<?php _e("Ajax error while updating feed", "gravity-forms-sendinblue" ) ?>')
                    };
                    mysack.runAJAX();

                    return true;
                }
            </script>
        <?php
        }

        private static function is_valid_login($user = null)
        {
            $lists = self::get_lists();

            update_option('gravity_forms_sb_valid_api', !empty($lists));

            return empty($lists) ? false : true;
        }

        /** get lists in SendinBlue */
        static function get_lists()
        {
            $access_key = get_option('gf_sendinblue_settings', null);
            if ($access_key == null)
                return false;
            $access_key = $access_key['username'];
            $mailin = new Mailin('https://api.sendinblue.com/v2.0', $access_key);

            $list_response = $mailin->get_lists();
            $lists = array();

            // check response
            if (!is_array($list_response))
                return $lists;

            if ($list_response['code'] != 'success')
                return $lists;

            $response_data = $list_response['data'];
            if (!is_array($response_data))
                return $lists;

            // get lists from response
            foreach ($response_data as $list) {
                $lists[] = array(
                    'id' => $list['id'],
                    'name' => $list['name']
                );
            }

            return $lists;
        }

        private static function get_api()
        {

//            if(!class_exists("CC_Utility")){
//                require_once("api/cc_class.php");
//            }

            //           $api = new CC_GF_SuperClass();
            //           $api->updateSettings();

            //          if(!$api || !empty($api->errorCode)) {
            //             return null;
            //        }

            //      return $api;
        }

        private static function edit_page()
        {
            ?>
            <style>
                .sendinblue_col_heading {
                    padding-bottom: 2px;
                    border-bottom: 1px solid #ccc;
                    font-weight: bold;
                }

                .sendinblue_field_cell {
                    padding: 6px 17px 0 0;
                    margin-right: 15px;
                }

                .gfield_required {
                    color: red;
                }

                .feeds_validation_error {
                    background-color: #FFDFDF;
                }

                .feeds_validation_error td {
                    margin-top: 4px;
                    margin-bottom: 6px;
                    padding-top: 6px;
                    padding-bottom: 6px;
                    border-top: 1px dotted #C89797;
                    border-bottom: 1px dotted #C89797
                }

                .left_header {
                    float: left;
                    width: 200px;
                }

                .margin_vertical_10 {
                    margin: 10px 0;
                }
            </style>
            <script type="text/javascript">
                var form = Array();
            </script>
            <div class="wrap">
                <h2 style="line-height:71px;"><a href='http://katz.si/6p' target='_blank'><img
                            alt="<?php _e("SendinBlue", "gravity-forms-sendinblue") ?>"
                            style="display:block; margin-right:10px;width:200px;margin-bottom: 5px;"
                            src="<?php echo self::get_base_url() ?>/images/logo.png"/></a><?php _e("SendinBlue Feed", "gravity-forms-sendinblue") ?>
                </h2>

                <div class="clear"></div>
                <?php

                //ensures valid credentials were entered in the settings page
                if (!get_option('gravity_forms_sb_valid_api')) {
                    ?>
                    <div class="error" id="message">
                        <p><?php echo sprintf(__("We are unable to login to SendinBlue with the provided credentials. Please make sure they are valid in the %sSettings Page%s.", "gravity-forms-sendinblue"), "<a href='?page=gf_settings&addon=Constant+Contact'>", "</a>"); ?></p>
                    </div>
                    <?php
                    return;
                }

                //getting setting id (0 when creating a new one)
                $id = !empty($_POST["sendinblue_setting_id"]) ? $_POST["sendinblue_setting_id"] : absint($_GET["id"]);
                $config = empty($id) ? array('is_active' => true) : GFSendinBlueData::get_feed($id);

                //getting merge vars from selected list (if one was selected)
                $merge_vars = empty($config['meta']['contact_list_id']) ? array() : self::listMergeVars();
                //updating meta information
                if (isset($_POST['gf_sendinblue_submit'])) {

                    list($list_id, $list_name) = self::get_sendinblue_list_details($_POST["gf_sendinblue_list"]);

                    $config["meta"]["contact_list_id"] = $list_id;
                    $config["meta"]["contact_list_name"] = $list_name;
                    $config["form_id"] = absint($_POST["gf_sendinblue_form"]);

                    $is_valid = true;
                    $merge_vars = self::listMergeVars();
                    $field_map = array();
                    foreach ($merge_vars as $var) {
                        $field_name = "sendinblue_map_field_" . $var["name"];
                        $mapped_field = stripslashes($_POST[$field_name]);
                        if (!empty($mapped_field)) {
                            $field_map[$var["name"]] = $mapped_field;
                        } else {
                            unset($field_map[$var["name"]]);
                            if ($var["name"] == "EMAIL")
                                $is_valid = false;
                        }
                    }

                    $config["meta"]["field_map"] = $field_map;
                    $config["meta"]["optin_enabled"] = !empty($_POST["sendinblue_optin_enable"]) ? true : false;
                    $config["meta"]["optin_field_id"] = !empty($config["meta"]["optin_enabled"]) ? $_POST["sendinblue_optin_field_id"] : "";
                    $config["meta"]["optin_operator"] = !empty($config["meta"]["optin_enabled"]) ? $_POST["sendinblue_optin_operator"] : "";
                    $config["meta"]["optin_value"] = $config["meta"]["optin_enabled"] ? $_POST["sendinblue_optin_value"] : "";

                    if ($is_valid) {
                        $id = GFSendinBlueData::update_feed($id, $config["form_id"], $config["is_active"], $config["meta"]);
                        ?>
                        <div class="updated fade"
                             style="padding:6px"><?php echo sprintf(__("Feed Updated. %sback to list%s", "gravity-forms-sendinblue"), "<a href='?page=gf_sendinblue'>", "</a>") ?></div>
                        <input type="hidden" name="sendinblue_setting_id" value="<?php echo $id ?>"/>
                    <?php
                    } else {
                        ?>
                        <div class="error"
                             style="padding:6px"><?php echo __("Feed could not be updated. Please enter all required information below.", "gravity-forms-sendinblue") ?></div>
                    <?php
                    }
                }

                ?>
                <form method="POST" action="<?php echo remove_query_arg('refresh'); ?>">
                    <input type="hidden" name="sendinblue_setting_id" value="<?php echo $id ?>"/>

                    <div class="margin_vertical_10">
                        <label for="gf_sendinblue_list"
                               class="left_header"><?php _e("SendinBlue list", "gravity-forms-sendinblue"); ?> <?php gform_tooltip("sendinblue_contact_list") ?></label>
                        <?php


                        //getting all contact lists
                        $lists = self::get_lists();

                        if (empty($lists)) {
                            echo __("Could not load SendinBlue contact lists.", "gravity-forms-sendinblue");
                        } else {
                            ?>
                            <select id="gf_sendinblue_list" name="gf_sendinblue_list"
                                    onchange="SelectList(jQuery(this).val());">
                                <option
                                    value=""><?php _e("Select a SendinBlue List", "gravity-forms-sendinblue"); ?></option>
                                <?php
                                $curr_contact_list_id = isset($config["meta"]["contact_list_id"]) ? $config["meta"]["contact_list_id"] : '';
                                foreach ($lists as $list) {
                                    echo '<option value="' . $list['id'] . '" ' . selected($list['id'], $curr_contact_list_id, false) . '>' . esc_html($list['name']) . '</option>';
                                }
                                ?>
                            </select>
                        <?php
                        }
                        ?>
                    </div>
                    <div id="sendinblue_form_container" valign="top"
                         class="margin_vertical_10" <?php echo empty($config["meta"]["contact_list_id"]) ? "style='display:none;'" : "" ?>>
                        <label for="gf_sendinblue_form"
                               class="left_header"><?php _e("Gravity Form", "gravity-forms-sendinblue"); ?> <?php gform_tooltip("sendinblue_gravity_form") ?></label>

                        <select id="gf_sendinblue_form" name="gf_sendinblue_form"
                                onchange="SelectForm(jQuery('#gf_sendinblue_list').val(), jQuery(this).val());">
                            <option value=""><?php _e("Select a form", "gravity-forms-sendinblue"); ?> </option>
                            <?php
                            $curr_form_id = isset($config['form_id']) ? $config['form_id'] : '';
                            $forms = RGFormsModel::get_forms();
                            foreach ($forms as $form) {
                                echo '<option value="' . absint($form->id) . '" ' . selected(absint($form->id), $curr_form_id, false) . '>' . esc_html($form->title) . '</option>';
                            }
                            ?>
                        </select>
                        &nbsp;&nbsp;
                        <img src="<?php echo GFSIB_Manager::get_base_url() ?>/images/loading.gif" id="sendinblue_wait"
                             style="display: none;"/>
                    </div>
                    <div id="sendinblue_field_group"
                         valign="top" <?php echo empty($config["meta"]["contact_list_id"]) || empty($config["form_id"]) ? "style='display:none;'" : "" ?>>
                        <div id="sendinblue_field_container" valign="top" class="margin_vertical_10">
                            <label for="sendinblue_fields"
                                   class="left_header"><?php _e("Map Fields", "gravity-forms-sendinblue"); ?> <?php gform_tooltip("sendinblue_map_fields") ?></label>

                            <div id="sendinblue_field_list">
                                <?php
                                if (!empty($config["form_id"])) {

                                    //getting list of all ConstantContact merge variables for the selected contact list
                                    if (empty($merge_vars)) {

                                        $merge_vars = self::listMergeVars($list_id);
                                    }


                                    //getting field map UI
                                    echo self::get_field_mapping($config, $config["form_id"], $merge_vars);

                                    //getting list of selection fields to be used by the optin
                                    $form_meta = RGFormsModel::get_form_meta($config["form_id"]);
                                    $selection_fields = GFCommon::get_selection_fields($form_meta, @$config["meta"]["optin_field_id"]);
                                }
                                ?>
                            </div>
                        </div>

                        <div id="sendinblue_submit_container" class="margin_vertical_10">
                            <input type="submit" name="gf_sendinblue_submit"
                                   value="<?php echo empty($id) ? __("Save Feed", "gravity-forms-sendinblue") : __("Update Feed", "gravity-forms-sendinblue"); ?>"
                                   class="button-primary"/>
                        </div>
                    </div>
                </form>
            </div>
            <script type="text/javascript">

                function SelectList(listId) {
                    console.log(listId);
                    if (listId) {
                        jQuery("#sendinblue_form_container").slideDown();
                        jQuery("#gf_sendinblue_form").val("");
                    }
                    else {
                        jQuery("#sendinblue_form_container").slideUp();
                        EndSelectForm("");
                    }
                }

                function SelectForm(listId, formId) {
                    if (!formId) {
                        jQuery("#sendinblue_field_group").slideUp();
                        return;
                    }

                    jQuery("#sendinblue_wait").show();
                    jQuery("#sendinblue_field_group").slideUp();

                    var mysack = new sack("<?php bloginfo( 'wpurl' ); ?>/wp-admin/admin-ajax.php");
                    mysack.execute = 1;
                    mysack.method = 'POST';
                    mysack.setVar("action", "gf_select_sendinblue_form");
                    mysack.setVar("gf_select_sendinblue_form", "<?php echo wp_create_nonce("gf_select_sendinblue_form") ?>");
                    mysack.setVar("list_id", listId);
                    mysack.setVar("form_id", formId);
                    mysack.encVar("cookie", document.cookie, false);
                    mysack.onError = function () {
                        jQuery("#sendinblue_wait").hide();
                        alert('<?php _e("Ajax error while selecting a form", "gravity-forms-sendinblue") ?>')
                    };
                    mysack.runAJAX();

                    return true;
                }

                function SetOptin(selectedField, selectedValue) {

                    //load form fields
                    jQuery("#sendinblue_optin_field_id").html(GetSelectableFields(selectedField, 20));
                    var optinConditionField = jQuery("#sendinblue_optin_field_id").val();

                    if (optinConditionField) {
                        jQuery("#sendinblue_optin_condition_message").hide();
                        jQuery("#sendinblue_optin_condition_fields").show();
                        jQuery("#sendinblue_optin_value").html(GetFieldValues(optinConditionField, selectedValue, 20));
                    }
                    else {
                        jQuery("#sendinblue_optin_condition_message").show();
                        jQuery("#sendinblue_optin_condition_fields").hide();
                    }
                }

                function EndSelectForm(fieldList, form_meta) {
                    //setting global form object
                    form = form_meta;

                    if (fieldList) {

                        SetOptin("", "");

                        jQuery("#sendinblue_field_list").html(fieldList);
                        jQuery("#sendinblue_field_group").slideDown();

                    }
                    else {
                        jQuery("#sendinblue_field_group").slideUp();
                        jQuery("#sendinblue_field_list").html("");
                    }
                    jQuery("#sendinblue_wait").hide();
                }

                function GetFieldValues(fieldId, selectedValue, labelMaxCharacters) {
                    if (!fieldId)
                        return "";

                    var str = "";
                    var field = GetFieldById(fieldId);
                    if (!field || !field.choices)
                        return "";

                    var isAnySelected = false;

                    for (var i = 0; i < field.choices.length; i++) {
                        var fieldValue = field.choices[i].value ? field.choices[i].value : field.choices[i].text;
                        var isSelected = fieldValue == selectedValue;
                        var selected = isSelected ? "selected='selected'" : "";
                        if (isSelected)
                            isAnySelected = true;

                        str += "<option value='" + fieldValue.replace("'", "&#039;") + "' " + selected + ">" + TruncateMiddle(field.choices[i].text, labelMaxCharacters) + "</option>";
                    }

                    if (!isAnySelected && selectedValue) {
                        str += "<option value='" + selectedValue.replace("'", "&#039;") + "' selected='selected'>" + TruncateMiddle(selectedValue, labelMaxCharacters) + "</option>";
                    }

                    return str;
                }

                function GetFieldById(fieldId) {
                    for (var i = 0; i < form.fields.length; i++) {
                        if (form.fields[i].id == fieldId)
                            return form.fields[i];
                    }
                    return null;
                }

                function TruncateMiddle(text, maxCharacters) {
                    if (text.length <= maxCharacters)
                        return text;
                    var middle = parseInt(maxCharacters / 2);
                    return text.substr(0, middle) + "..." + text.substr(text.length - middle, middle);
                }

                function GetSelectableFields(selectedFieldId, labelMaxCharacters) {
                    var str = "";
                    var inputType;
                    for (var i = 0; i < form.fields.length; i++) {
                        fieldLabel = form.fields[i].adminLabel ? form.fields[i].adminLabel : form.fields[i].label;
                        inputType = form.fields[i].inputType ? form.fields[i].inputType : form.fields[i].type;
                        if (inputType == "checkbox" || inputType == "radio" || inputType == "select") {
                            var selected = form.fields[i].id == selectedFieldId ? "selected='selected'" : "";
                            str += "<option value='" + form.fields[i].id + "' " + selected + ">" + TruncateMiddle(fieldLabel, labelMaxCharacters) + "</option>";
                        }
                    }
                    return str;
                }

            </script>

        <?php

        }

        public static function add_permissions()
        {
            global $wp_roles;
            $wp_roles->add_cap("administrator", "gravityforms_sendinblue");
            $wp_roles->add_cap("administrator", "gravityforms_sendinblue_uninstall");
        }

        //Target of Member plugin filter. Provides the plugin with Gravity Forms lists of capabilities
        public static function members_get_capabilities($caps)
        {
            return array_merge($caps, array("gravityforms_sendinblue", "gravityforms_sendinblue_uninstall"));
        }

        public static function disable_sendinblue()
        {
            delete_option("gf_sendinblue_settings");
        }

        public static function select_sendinblue_form()
        {

            check_ajax_referer("gf_select_sendinblue_form", "gf_select_sendinblue_form");
            $form_id = intval(@$_POST["form_id"]);
            $list_id = !empty($_POST["list_id"]) ? self::get_sendinblue_list_endpoint($_POST["list_id"]) : '';
            $setting_id = intval(@$_POST["setting_id"]);

            //getting list of all SendinBlue merge variables for the selected contact list
            $merge_vars = self::listMergeVars();

            //getting configuration
            $config = GFSendinBlueData::get_feed($setting_id);

            //getting field map UI
            $str = self::get_field_mapping($config, $form_id, $merge_vars);

            //fields meta
            $form = RGFormsModel::get_form_meta($form_id);
            //$fields = $form["fields"];
            die("EndSelectForm('" . str_replace("'", "\'", $str) . "', " . GFCommon::json_encode($form) . ");");
        }


        /**
         * Convert CC endpoint id into a number id to be used on forms (avoid issues with more strict servers)
         *
         * @access public
         * @static
         * @param mixed $endpoint
         * @return string $id
         */
        public static function get_cc_list_short_id($endpoint)
        {

            if (empty($endpoint)) {
                return '';
            }

            if (false !== ($pos = strrpos(rtrim($endpoint, '/'), '/'))) {
                return trim(substr($endpoint, $pos + 1));
            }

            return '';
        }


        /**
         * Given a list short id (just the numeric part) return the list endpoint
         *
         * @access public
         * @static
         * @param integer $list_id
         * @return string $endpoint
         */
        public static function get_sendinblue_list_endpoint($list_id)
        {

            if (empty($list_id)) {
                return '';
            }

            $lists = self::get_lists();

            foreach ($lists as $list) {
                if ($list['id'] == $list_id) {
                    return $list['id'];
                }

            }

            return '';
        }


        /**
         * Given a list short id (just the numeric part) return the list details (endpoint and title)
         *
         * @access public
         * @static
         * @param mixed $list_id
         * @return array (id, title)
         */
        public static function get_sendinblue_list_details($list_id)
        {

            if (empty($list_id)) {
                return '';
            }

            $lists = self::get_lists();

            foreach ($lists as $list) {
                if ($list['id'] == $list_id) {
                    return array($list['id'], $list['name']);
                }
            }

            return '';
        }


        private static function get_field_mapping($config, $form_id, $merge_vars)
        {

            //getting list of all fields for the selected form
            $form_fields = self::get_form_fields($form_id);

            $str = "<table cellpadding='0' cellspacing='0'><tr><td class='sendinblue_col_heading'>" . __("List Fields", "gravity-forms-sendinblue") . "</td><td class='sendinblue_col_heading'>" . __("Form Fields", "gravity-forms-sendinblue") . "</td></tr>";
            foreach ($merge_vars as $var) {
                $selected_field = @$config["meta"]["field_map"][$var["name"]];
                $required = $var["name"] == "EMAIL" ? "<span class='gfield_required'>*</span>" : "";
                $error_class = $var["name"] == "EMAIL" && empty($selected_field) && !empty($_POST["gf_sendinblue_submit"]) ? " feeds_validation_error" : "";
                $str .= "<tr class='$error_class'><td class='sendinblue_field_cell'>" . $var["name"] . " $required</td><td class='sendinblue_field_cell'>" . self::get_mapped_field_list($var["name"], $selected_field, $form_fields) . "</td></tr>";
            }
            $str .= "</table>";

            return $str;
        }

        public static function get_form_fields($form_id)
        {
            $form = RGFormsModel::get_form_meta($form_id);
            $fields = array();

            //Adding default fields
            array_push($form["fields"], array("id" => "date_created", "label" => __("Entry Date", "gravity-forms-sendinblue")));
            array_push($form["fields"], array("id" => "ip", "label" => __("User IP", "gravity-forms-sendinblue")));
            array_push($form["fields"], array("id" => "source_url", "label" => __("Source Url", "gravity-forms-sendinblue")));

            if (is_array($form["fields"])) {
                foreach ($form["fields"] as $field) {
                    if (isset($field["inputs"]) && is_array($field["inputs"])) {

                        //If this is an address field, add full name to the list
                        if (RGFormsModel::get_input_type($field) == "address")
                            $fields[] = array($field["id"], GFCommon::get_label($field) . " (" . __("Full", "gravity-forms-sendinblue") . ")");

                        foreach ($field["inputs"] as $input)
                            $fields[] = array($input["id"], GFCommon::get_label($field, $input["id"]));
                    } else if (empty($field["displayOnly"])) {
                        $fields[] = array($field["id"], GFCommon::get_label($field));
                    }
                }
            }
            return $fields;
        }

        private static function get_address($entry, $field_id)
        {
            $street_value = str_replace("  ", " ", trim($entry[$field_id . ".1"]));
            $street2_value = str_replace("  ", " ", trim($entry[$field_id . ".2"]));
            $city_value = str_replace("  ", " ", trim($entry[$field_id . ".3"]));
            $state_value = str_replace("  ", " ", trim($entry[$field_id . ".4"]));
            $zip_value = trim($entry[$field_id . ".5"]);
            $country_value = GFCommon::get_country_code(trim($entry[$field_id . ".6"]));

            $address = $street_value;
            $address .= !empty($address) && !empty($street2_value) ? "  $street2_value" : $street2_value;
            $address .= !empty($address) && (!empty($city_value) || !empty($state_value)) ? "  $city_value" : $city_value;
            $address .= !empty($address) && !empty($city_value) && !empty($state_value) ? "  $state_value" : $state_value;
            $address .= !empty($address) && !empty($zip_value) ? "  $zip_value" : $zip_value;
            $address .= !empty($address) && !empty($country_value) ? "  $country_value" : $country_value;

            return $address;
        }

        public static function get_mapped_field_list($variable_name, $selected_field, $fields)
        {
            $field_name = "sendinblue_map_field_" . $variable_name;
            $str = "<select name='$field_name' id='$field_name'><option value=''>" . __("", "gravity-forms-sendinblue") . "</option>";
            foreach ($fields as $field) {
                $field_id = $field[0];
                $field_label = $field[1];

                $selected = $field_id == $selected_field ? "selected='selected'" : "";
                $str .= "<option value='" . $field_id . "' " . $selected . ">" . $field_label . "</option>";
            }
            $str .= "</select>";
            return $str;
        }

        public static function export($entry, $form)
        {
            //loading data class
            require_once(self::get_base_path() . "/inc/data.php");

            //getting all active feeds
            $feeds = GFSendinBlueData::get_feed_by_form($form["id"], true);

            foreach ($feeds as $feed) {
                //only export if user has opted in
                if (self::is_optin($form, $feed)) {
                    self::export_feed($entry, $form, $feed);
                }
            }
        }

        public static function export_feed($entry, $form, $feed)
        {
            $email_field_id = $feed["meta"]["field_map"]["EMAIL"];
            $email = $entry[$email_field_id];
            $merge_vars = array('');
            foreach ($feed["meta"]["field_map"] as $var_tag => $field_id) {

                $field = RGFormsModel::get_field($form, $field_id);
                $field_type = RGFormsModel::get_input_type($field);

                if ($field_id == intval($field_id) && $field_type == "address") {
                    //handling full address
                    $merge_vars[$var_tag] = self::get_address($entry, $field_id);
                } elseif ($field_type == 'date') {
                    $merge_vars[$var_tag] = apply_filters('gravityforms_sendinblue_change_date_format', $entry[$field_id]);
                } else {
                    $merge_vars[$var_tag] = $entry[$field_id];
                }
            }

            $retval = self::listSubscribe($feed["meta"]["contact_list_id"], $merge_vars);

            if ($retval == TRUE ) {
                self::add_note($entry["id"], __('Successfully added/updated in SendinBlue Contacts.', 'gravity-forms-sendinblue'));
            } else {
                self::add_note($entry["id"], __('Errors when adding/updating in SendinBlue Contacts.', 'gravity-forms-sendinblue'));
            }
        }

        /**
         * Add note to GF Entry
         * @param int $id Entry ID
         * @param string $note Note text
         */
        static private function add_note($id, $note)
        {

            if (!apply_filters('gravityforms_sendinblue_add_notes_to_entries', true) || !class_exists('RGFormsModel')) {
                return;
            }

            RGFormsModel::add_note($id, 0, __('Gravity Forms SendinBlue Add-on'), $note);
        }

        public static function uninstall()
        {

            //loading data lib
            //require_once(self::get_base_path() . "/data.php");

            if (!GFSIB_Manager::has_access("gravityforms_sendinblue_uninstall"))
                die(__("You don't have adequate permission to uninstall SendinBlue Add-On.", "gravity-forms-sendinblue"));

            //droping all tables
            GFSIB_Manager::drop_tables();

            //removing options
            delete_option("gf_sendinblue_settings");
            delete_option("gf_sendinblue_version");
            delete_option("gravity_forms_sb_valid_api");

            //Deactivating plugin
            $plugin = "gravity-forms-sendinblue/gfsendinblue.php";
            deactivate_plugins($plugin);
            update_option('recently_activated', array($plugin => time()) + (array)get_option('recently_activated'));
        }

        public static function is_optin($form, $settings)
        {
            $config = $settings["meta"];
            $operator = $config["optin_operator"];

            $field = RGFormsModel::get_field($form, $config["optin_field_id"]);
            $field_value = RGFormsModel::get_field_value($field, array());
            $is_value_match = is_array($field_value) ? in_array($config["optin_value"], $field_value) : $field_value == $config["optin_value"];

            return !$config["optin_enabled"] || empty($field) || ($operator == "is" && $is_value_match) || ($operator == "isnot" && !$is_value_match);
        }

        public static function listMergeVars()
        {
            $access_key = get_option('gf_sendinblue_settings', null);
            if ($access_key == null)
                return false;
            $access_key = $access_key['username'];
            $mailin = new Mailin('https://api.sendinblue.com/v2.0', $access_key);

            $response = $mailin->get_attribute('normal');
            $attribute_list = array();
            if (is_array($response)) {
                if ($response['code'] == 'success') {

                    // store api info
                    $settings = array(
                        'access_key' => $access_key,
                    );
                    update_option(SIB_Manager::main_option_name, $settings);
                    // store attribute list

                    $attribute_list = $response['data'];
                    array_unshift($attribute_list, array('name' => 'EMAIL','type' => 'text'));
                }
            }

            return $attribute_list;
        }

        static public function listSubscribe($id, $merge_vars) {
            $params = $merge_vars;

            foreach($params as $key => $p) {
                $p = trim($p);
                if(empty($p) && $p != '0') {
                    unset($params[$key]);
                }
            }

            $params["lists"] = array($id); //array(preg_replace('/(?:.*?)\/lists\/(\d+)/ism','$1',$id));
            $params['mail_type'] = strtolower(@$params['mail_type']);
            if($params['mail_type'] != 'html' && $params['mail_type'] != 'text') {
                $params['mail_type'] = 'html';
            }

            $access_key = get_option('gf_sendinblue_settings', null);
            if ($access_key == null)
                return false;
            $access_key = $access_key['username'];
            $mailin = new Mailin('https://api.sendinblue.com/v2.0', $access_key);
            $response = $mailin->get_user($merge_vars['EMAIL']);
            if ($response['code'] == 'failure') {
                $listids = array();
            }
            else if(isset($response['data'])) {
                if ($response['data']['blacklisted'] == 1) {
                    return FALSE;
                }
                $listids = $response['data']['listid'];
                if (!isset($listids) || !is_array($listids)) {
                    $listids = array();
                }
            }
            array_push($listids, $id);
            $response = $mailin->create_update_user($merge_vars['EMAIL'], $merge_vars, 0, $listids, null);
            if ($response['code'] == 'success') {
                return TRUE;
            }
            return FALSE;
        }

        public static function drop_tables(){
            global $wpdb;
            $wpdb->query("DROP TABLE IF EXISTS " . GFSendinBlueData::get_constantcontact_table_name());
        }
    }


}