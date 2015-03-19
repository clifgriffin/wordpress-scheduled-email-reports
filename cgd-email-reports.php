<?php
/*
Plugin Name: WordPress Scheduled Email Reports
Plugin URI: http://cgd.io
Description:  Generate scheduled email reports. 
Version: 1.0.0
Author: CGD Inc.
Author URI: http://cgd.io

------------------------------------------------------------------------
Copyright 2009-2015 Clif Griffin Development Inc.

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/

class CGD_EmailReports {
	var $post_type = "cgd_email_report";
	var $prefix = "_cgd_";
	
	public function __construct() {
		
		// Register our post type
		add_action('init', array($this, 'register_post_types') );
		
		// Add some additional details to the emails
		add_action( 'cmb2_init', array($this, 'register_metaboxes') );
		
		// Add Weekly Schedule to Cron
		add_filter( 'cron_schedules', array($this, 'cron_add_weekly'), 100, 1 );
		
		// Setup crons
		add_action('save_post', array($this, 'update_crons') );
		
		// Clear crons on post delete
		add_action('delete_post', array($this, 'clear_report_schedule') );
		
		// Setup cron actions
		add_action('init', array($this, 'setup_cron_actions') );
		
		// Activate / Deactivate
		register_activation_hook(__FILE__, array($this, 'activate') );
		register_deactivation_hook(__FILE__, array($this, 'deactivate') ); 
	}
	
	function register_post_types() {
		
	    $labels = array( 
	        'name' => _x( 'Email Reports', 'cgd_email_report' ),
	        'singular_name' => _x( 'Email Report', 'cgd_email_report' ),
	        'add_new' => _x( 'Add New', 'cgd_email_report' ),
	        'add_new_item' => _x( 'Add New Email Report', 'cgd_email_report' ),
	        'edit_item' => _x( 'Edit Email Report', 'cgd_email_report' ),
	        'new_item' => _x( 'New Email Report', 'cgd_email_report' ),
	        'view_item' => _x( 'View Email Report', 'cgd_email_report' ),
	        'search_items' => _x( 'Search Email Reports', 'cgd_email_report' ),
	        'not_found' => _x( 'No email reports found', 'cgd_email_report' ),
	        'not_found_in_trash' => _x( 'No email reports found in Trash', 'cgd_email_report' ),
	        'parent_item_colon' => _x( 'Parent Email Report:', 'cgd_email_report' ),
	        'menu_name' => _x( 'Email Reports', 'cgd_email_report' ),
	    );
	
	    $args = array( 
	        'labels' => $labels,
	        'hierarchical' => false,
	        
	        'supports' => array( 'title', 'editor', 'revisions' ),
	        
	        'public' => false,
	        'show_ui' => true,
	        'show_in_menu' => true,
	        
	        'menu_icon' => 'dashicons-analytics',
	        'show_in_nav_menus' => false,
	        'publicly_queryable' => false,
	        'exclude_from_search' => true,
	        'has_archive' => false,
	        'query_var' => false,
	        'can_export' => true,
	        'rewrite' => false,
	        'capability_type' => 'post'
	    );
	
	    register_post_type( $this->post_type, $args );
	    
	}
	
	function register_metaboxes() {
		
		$scheduling_metabox = new_cmb2_box( array(
			'id'            => $this->prefix . 'recipient_metabox',
			'title'         => __( 'Recipients', 'cmb2' ),
			'object_types'  => array( 'cgd_email_report', ), // Post type
			'context'       => 'normal',
			'priority'      => 'high',
			'show_names'    => true, // Show field names on the left
		) );
		
		$scheduling_metabox->add_field( array(
			'name' => __( 'Recipients', 'cmb2' ),
			'desc' => __( 'Who should receive this email report?', 'cmb2' ),
			'id'   => $this->prefix . 'email',
			'type' => 'text_email',
			'repeatable' => true,
		) );

		$scheduling_metabox = new_cmb2_box( array(
			'id'            => $this->prefix . 'scheduling_metabox',
			'title'         => __( 'Scheduling', 'cmb2' ),
			'object_types'  => array( 'cgd_email_report', ), // Post type
			'context'       => 'normal',
			'priority'      => 'high',
			'show_names'    => true, // Show field names on the left
		) );
		
		$scheduling_metabox->add_field( array(
			'name' => __( 'Start Time', 'cmb2' ),
			'desc' => __( 'Desired start time.', 'cmb2' ),
			'id'   => $this->prefix . 'time',
			'type' => 'text_time',
			'default' => '08:00:00',
		) );
		
		$options = array();
		$intervals = wp_get_schedules(); 
		
		foreach($intervals as $key => $label) {
			if ($key == 'five_minutes_interval') continue;
			
			$options[$key] = $label['display'];
		}
		
		$scheduling_metabox->add_field( array(
			'name'             => __( 'Recurrence', 'cmb2' ),
			'desc'             => __( 'How often should this email send?', 'cmb2' ),
			'id'               => $this->prefix . 'recurrence',
			'type'             => 'select',
			'show_option_none' => true,
			'options'          => $options,
		) );
	}
	
	function cron_add_weekly( $schedules ) {
		
		// Adds once weekly to the existing schedules.
		$schedules['weekly'] = array(
			'interval' => 604800,
			'display' => __( 'Once Weekly' )
		);
		
		return $schedules;
	}
	 
	function update_crons($post_id) {
				
		// Do we have the right post type
		if ( $this->post_type == get_post_type($post_id) ) {
			 
			wp_clear_scheduled_hook( 'email_report_' . $post_id );
			 
			// If post isn't published, we're done son
			if ( 'publish' != get_post_status($post_id) ) return;
			
			// Setup new hook
			wp_schedule_event( $this->get_report_time($post_id), $this->get_report_schedule($post_id), 'email_report_' . $post_id, $post_id ); 
		}
	}
	
	function get_report_time($post_id) {
		$time = get_post_meta($post_id, $this->prefix . 'time', true);
		
		$datetime = new DateTime($time);
		$tz = new DateTimeZone( get_option('timezone_string') );
		$datetime->setTimeZone($tz);
		
		return $datetime->getTimestamp();
	}
	
	function get_report_schedule($post_id) {
		$schedule = get_post_meta($post_id, $this->prefix . 'recurrence', true);
		
		if ( empty($schedule) ) $schedule = 'daily';
		
		return $schedule;
	}
	
	function get_report_message($post_id) {
		$post = get_post($post_id);
		setup_postdata($post);
		
		$message = apply_filters('the_content', get_the_content() );
		
		ob_start();
		include('partials/email-template.php');
		
		return ob_get_clean();
	}
	
	function setup_cron_actions() {
		$reports = get_posts( array('post_type' => $this->post_type, 'posts_per_page' => 1000 ) );
		
		foreach($reports as $rp) {
			add_action('email_report_' . $rp->ID, array($this, 'send_report'), 10, 1 );
		}
	}
	
	function send_report($post_id) {
		$post = get_post($post_id);
		setup_postdata($post);
		
		$recipients = get_post_meta($post_id, $this->prefix . 'email'); 
		$recipients = $recipients[0];
		$subject = get_the_title($post_id);
		$message = $this->get_report_message($post_id); 
		
		// Switch to HTML
		add_filter('wp_mail_content_type', array($this, 'set_content_type_html') );
		$headers = array('Content-Type: text/html; charset=UTF-8');
		
		wp_mail($recipients, $subject, $message, $headers);
		
		// Remove filter to prevent contaminating later emails
		remove_filter('wp_mail_content_type', array($this, 'set_content_type_html') );
		
		wp_reset_postdata();
	}
	
	function set_content_type_html($content_type) {
		return 'text/html';
	}
	
	function clear_report_schedule($post_id) {
		wp_clear_scheduled_hook( 'email_report_' . $post_id );
	}
	
	function activate() {
		$reports = get_posts( array('post_type' => $this->post_type, 'posts_per_page' => 1000 ) );
		
		foreach($reports as $rp) {
			wp_schedule_event( $this->get_report_time($rp->ID), $this->get_report_schedule($rp->ID), 'email_report_' . $rp->ID, $rp->ID ); 
		}
	}
	
	function deactivate() {
		$reports = get_posts( array('post_type' => $this->post_type, 'posts_per_page' => 1000 ) );
		
		foreach($reports as $rp) {
			wp_clear_scheduled_hook( 'email_report_' . $rp->ID );
		}
	}
}

$CGD_EmailReports = new CGD_EmailReports();