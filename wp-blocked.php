<?php
/*
Plugin Name: WP Blocked
Plugin URI: http://github.com/u451f/wp-blocked
Description: Wordpress plugin to interact with the Blocked-Middleware by OpenRightsGroup. API credentials can be configured via a settings page.
Version: 1.0
Author: Ulrike Uhlig, Martin Gutsch
Author URI: http://curlybracket.net
License: GPL3+
Text Domain: wp-blocked 
Domain Path: /languages/
*/

/*
    Copyright 2015 Ulrike Uhlig <u@curlybracket.net>, Martin Gutsch <gutsch@zwoelf.net>

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/* Plugin l10n */
function wp_blocked_init() {
	 $plugin_dir = basename(dirname(__FILE__));
	 load_plugin_textdomain( 'wp-blocked', false, "$plugin_dir/languages" );
}
add_action('plugins_loaded', 'wp_blocked_init');

// fetch results from server
function fetch_results($URL, $SSL=false, $fetch_stats=false) {
	require_once "lib/BlockedUrl.php";

	// load $API_KEY, $API_EMAIL, $HOST, $URL_STATUS via WP options
	$options = get_option('wp_blocked_option_name');

	if(empty($options['API_KEY']) OR empty($options['API_EMAIL']) OR empty($options['HOST'])) {
		// throw error
		echo __("Missing options.", 'wp-blocked');
	} else {
        
        // API change in BlockedURL 0.2.x => 0.3.0!

        $blocked = new BlockedUrl( $options['API_KEY'], $options['API_EMAIL'], $URL, $options['HOST'], $SSL ); // false = disable SSL peer verification

		// push your URL to network, and fetch response
		$pushed = $blocked->push_request()->push_response();
		// print_r($pushed);

		// yields:
		// array(
		//       "hash"    => string,
		//       "queued"  => bool,
		//       "success" => bool,
		//       "uuid"    => int
		// )

		// retrieve URL status
		$status = $blocked->get_status()->status_response();
		// print_r($status);

		// yields:
		// array(
		//       "url-status" => string( "ok"|"blocked" ),
		//       "categories" => array( string ),
		//       "results"    => array(
		//            blocktype               => 'what',
		//            category                => 'ever',
		//            first_blocked_timestamp => '2015-03-19 12:39:48',
		//            last_blocked_timestamp  => '2015-03-19 12:39:48',
		//            network_name            => 'Fake ISP Ltd',
		//            status                  => 'ok',
		//            status_timestamp        => '2015-04-30 22:46:54'
		//               ...
		//       )
		// )

		// return $status;

		if($fetch_stats === true) {
			$stats = $blocked->get_daily_stats(10)->daily_stats_response();
			return $stats;
		}

		return format_results($status);	
	}
}

// create HTML output for status results
function format_results($status) {
	if($status['success'] == 1) {
		$output .= '<h2 class="url-searched">'.__("Results for", 'wp-blocked').' '. $status['url'].'</h2>';
		$output .= '<h3 class="url-status">'.__("Status", 'wp-blocked').' '. $status['url-status'].'</h3>';
		if(count($status['results']) > 0) {
			$output .= '<table class="url-results">';
			$output .= '<thead><tr><th>'.__('ISP', 'wp-blocked').'</th><th>'.__('Result', 'wp-blocked').'</th><th>'.__('Last check on', 'wp-blocked').'</th><th>'.__('Last block on', 'wp-blocked').'</th></thead>';
			foreach ($status['results'] as $result) {
				// load translations
				if($result['status'] == 'blocked') {$readable_status = __('blocked', 'wp-blocked');}
				else if($result['status'] == 'ok') {$readable_status = __('ok', 'wp-blocked');}
				else if($result['status'] == 'error') {$readable_status = __('error', 'wp-blocked');}
				else if($result['status'] == 'dns-error') {$readable_status = __('DNS error', 'wp-blocked');}
				else if($result['status'] == 'timeout') {$readable_status = __('timeout', 'wp-blocked');}
				else if($result['status'] == 'unknown') {$readable_status = __('unknown', 'wp-blocked');}

				// create css classes for rows
				$css_class = strtolower($result['status']);
				if($result['first_blocked_timestamp']) $css_class .= " prior-block";

				// if there is no first_blocked_ts this has never been blocked & we need to assign the current ts to last_blocked_ts
				$first_blocked_timestamp = $result['first_blocked_timestamp'] ?:  __('No record of prior block', 'wp-blocked');
				$last_blocked_timestamp = $result['last_blocked_timestamp'] ?: $result['status_timestamp'];
				
				// html output
				$output .= '<tr class="'.$css_class.'">';
				$output .= '<td>'.$result['network_name'].'</td>';
				$output .= '<td>'.$readable_status.'</td>';
				$output .= '<td>'.$last_blocked_timestamp.'</td>';
				$output .= '<td>'.$first_blocked_timestamp.'</td>';
				$output .= '</tr>';
			}
			$output .= '</table>';
			$output .= '<p class="permlink"><a href="'.get_permalink($post->ID).'?wp_blocked_url='.$status['url'].'">'. __("Permalink for this result:", 'wp-blocked').' '.get_permalink($post->ID).'?wp_blocked_url='.$status['url'].'</a></p>';
		}
	} else {
		$output .= '<p class="error">'.__("Could not retrieve results.", 'wp-blocked').'</p>';
	}
	return $output;
}

function display_results() {
	global $post, $polylang;
	if (function_exists('pll_current_language')) {
		$curLocale = pll_current_language('locale');
	}
	$options = get_option('wp_blocked_option_name');

	if(isset($_POST['wp_blocked_url']) OR isset($_GET['wp_blocked_url']) && is_page($options["resultspage_$curLocale"])) {
		if(isset($_GET['wp_blocked_url'])) {
			$URL = sanitize_url($_GET['wp_blocked_url']);
		} else {
			$URL = sanitize_url($_POST['wp_blocked_url']);
		}
		// check if URL is SSL and if yes, then set $SSL to true
		if(substr($URL, 0, 4) == "https") $SSL = true; 
		else $SSL = false;
		$output = $post->post_content.'<hr />'.fetch_results($URL, $SSL);
	} else {
		$output = $post->post_content;
	}
	return $output;
}
add_filter( 'the_content', 'display_results', 4, 0);

// create a shortcode which will insert a form [blocked_test_url]
function wp_blocked_url_shortcode() {
	global $polylang;
	if (function_exists('pll_current_language')) {
		$curLocale = pll_current_language('locale');
	}

	$options = get_option('wp_blocked_option_name');
	if(isset($_GET['wp_blocked_url'])) $value = sanitize_url($_GET['wp_blocked_url']);
	else if(isset($_POST['wp_blocked_url'])) $value = sanitize_url($_POST['wp_blocked_url']);
    	
	$form = '<form class="form wp-blocked-form" method="POST" action="'.get_permalink($options["resultspage_$curLocale"]).'" validate autocomplete="on">';
	$form .= '<input placeholder="'. __('Test if this URL is blocked', 'wp-blocked').'" type="url" value="'.$value.'" name="wp_blocked_url" required onblur="checkURL(this)" /><input type="submit" value="'.__('send', 'wp-blocked').'" class="submit" /></form>';
	return $form;
}
add_shortcode( 'blocked_test_url', 'wp_blocked_url_shortcode' );

// call javascript & style
function blocked_scripts() {
	wp_enqueue_style( 'blocked', plugins_url('', __FILE__).'/css/blocked.css' );
	wp_enqueue_script( 'blocked', plugins_url('', __FILE__).'/js/blocked.js', 0, 0, true );
}
add_action( 'wp_enqueue_scripts', 'blocked_scripts' );

// implement a way to display statistics of blocked URLs
function wp_blocked_statistics_shortcode() {
	global $polylang;
	if (function_exists('pll_current_language')) {
		$curLocale = pll_current_language('locale');
	}

	if(isset($_GET['wp_blocked_url'])) {
		$URL = sanitize_url($_GET['wp_blocked_url']);
	} else {
		$URL = sanitize_url($_POST['wp_blocked_url']);
	}

	// check if URL is SSL and if yes, then set $SSL to true
	if(substr($URL, 0, 4) == "https") 
		$SSL = true; 
	else 
		$SSL = false;
	$output = fetch_results($URL, $SSL, true);

	if($output['success'] == 1) {
		$html_output = '<h2 class="widget-title">'.__('Statistics', 'wp-blocked').'</h2>';
		foreach ($output['stats'] as $date => $item) {
			$percent = 100/100000*$item['blocked'];
			$percent = number_format((float)$percent, 2, '.', '');
			$html_output .= '<div class="blocked-item">';
			$html_output .= '<span class="blocked_sites_percent">'.$percent.'%</span>';
			$html_output .= '<span class="date">'.$date.'</span>';
			$html_output .= '<span class="blocked_sites">'.$item['blocked'].' <i>'.__('blocked sites', 'wp-blocked').'</i></span>';
			$html_output .= '</div>';
		}
	}
	echo $html_output;
}
add_shortcode( 'blocked_display_stats', 'wp_blocked_statistics_shortcode' );

function get_languages() {
	// check configured languages via polylang plugin.
	global $polylang;
	if (isset($polylang)) {
		$languages = $polylang->get_languages_list();
		return $languages;
	}
}


// Create configuration page for wp-admin. Each domain shall configure their API_KEY, API_EMAIL, HOST and results page.
class wpBlockedSettingsPage {
    /**
     * Holds the values to be used in the fields callbacks
     */
    private $options;

    /**
     * Start up
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
        add_action( 'admin_init', array( $this, 'page_init' ) );
    }

    /**
     * Add options page
     */
    public function add_plugin_page() {
        // This page will be under "Settings"
        add_options_page(
            'Settings Admin',
            'WP Blocked Settings',
            'manage_options',
            'wp-blocked-settings',
            array( $this, 'create_admin_page' )
        );
    }

    /**
     * Options page callback
     */
    public function create_admin_page() {
        // Set class property
        $this->options = get_option( 'wp_blocked_option_name' );
        ?>
        <div class="wrap">
            <?php screen_icon(); ?>
            <h2><?php _e('Settings WP Blocked'); ?></h2>
            <form method="post" action="options.php">
            <?php
                // This prints out all hidden setting fields
                settings_fields( 'wp_blocked_option_group' );
                do_settings_sections( 'wp-blocked-settings' );
                submit_button();
            ?>
            </form>
        </div>
        <?php
    }

    /**
     * Register and add settings
     */
    public function page_init() {
        register_setting(
            'wp_blocked_option_group', // Option group
            'wp_blocked_option_name', // Option name
            array( $this, 'sanitize' ) // Sanitize
        );

        add_settings_section(
            'wp_blocked_section_general', // ID
            'WP Blocked Settings', // Title
            array( $this, 'print_section_info' ), // Callback
            'wp-blocked-settings' // Page
        );
        add_settings_field(
            'API_EMAIL',
            'API Email',
            array( $this, 'api_email_callback' ),
            'wp-blocked-settings',
            'wp_blocked_section_general'
        );
        add_settings_field(
            'API_KEY',
            'API Key',
            array( $this, 'api_key_callback' ),
            'wp-blocked-settings',
            'wp_blocked_section_general'
        );
        add_settings_field(
            'HOST',
            'HOST URL or IP',
            array( $this, 'host_callback' ),
            'wp-blocked-settings',
            'wp_blocked_section_general'
        );

	$languages = get_languages();
	if($languages) {
		foreach($languages as $lang) {
			add_settings_field(
			    'resultspage_'.$lang->locale,
			    'Page ID for results in '.$lang->name,
			    array( $this, 'resultspage_status_callback' ),
			    'wp-blocked-settings',
			    'wp_blocked_section_general',
			    array( 'locale' => $lang->locale )
			);
		}
	} else {
		add_settings_field(
		    'resultspage_',
		    'Page ID for results',
		    array( $this, 'resultspage_status_callback' ),
		    'wp-blocked-settings',
		    'wp_blocked_section_general'
		);
	}
    }

    /**
     * Sanitize each setting field as needed
     *
     * @param array $input Contains all settings fields as array keys
     */
    public function sanitize( $input ) {

        if( !empty( $input['API_KEY'] ) )
            $input['API_KEY'] = sanitize_text_field( $input['API_KEY'] );
        if( !empty( $input['API_EMAIL'] ) )
            $input['API_EMAIL'] = sanitize_email( $input['API_EMAIL'] );
        if( !empty( $input['HOST'] ) )
            $input['HOST'] = sanitize_text_field( $input['HOST'] );
	$languages = get_languages();
	if($languages) {
		foreach($languages as $lang) {
			$locale = $lang->locale;
			if( !empty( $input["resultspage_$locale"] ) ) {
			    $input["resultspage_$locale"] = sanitize_text_field( $input["resultspage_$locale"] );
			}
		}
	} else {
		if( !empty( $input['resultspage_'] ) ) {
		    $input['resultspage_'] = sanitize_text_field( $input['resultspage_'] );
		}
	}
        return $input;
    }

    /**
     * Print the Section text
     */
    public function print_section_info() {
        print _e('Please fill in the corresponding fields.');
    }

    /**
     * Get the settings option array and print one of its values
     */

    public function api_email_callback() {
        printf(
            '<input type="text" id="API_EMAIL" name="wp_blocked_option_name[API_EMAIL]" value="%s" class="regular-text ltr" required />',
            esc_attr( $this->options['API_EMAIL'])
        );
    }
    
    public function api_key_callback() {
        printf(
            '<input type="text" id="API_KEY" name="wp_blocked_option_name[API_KEY]" value="%s" class="regular-text ltr" required />',
            esc_attr( $this->options['API_KEY'])
        );
    }

    public function host_callback() {
        printf(
            '<input type="text" id="HOST" name="wp_blocked_option_name[HOST]" value="%s" class="regular-text ltr" required />',
            esc_attr( $this->options['HOST'])
        );
    }

    public function resultspage_status_callback($args) {
	$locale = $args['locale'];
	printf(
	    '<input type="number" id="resultspage_'.$locale.'" name="wp_blocked_option_name[resultspage_'.$locale.']" value="%s" class="regular-text ltr" required />',
	    esc_attr( $this->options["resultspage_$locale"])
	);
    }
}

if( is_admin() )
    $wp_blocked_settings_page = new wpBlockedSettingsPage();
?>
