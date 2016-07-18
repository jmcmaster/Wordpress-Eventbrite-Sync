<?php

/**
 * Plugin Name: Eventbrite API Integration
 * Description: This plugin pulls in Eventbrite events and stores them in a custom post type
 * Version: 0.1
 * Author: Jason McMaster
 */

class WordpressEventbriteSync {

    private $endpoint = 'https://www.eventbriteapi.com/v3/';
    private $token = 'TOKEN';
    private $eventbrite_user_email = 'your@email.com';
    private $post_type = 'eventbrite_events';

    public function __construct() {
        $eventbrite_user = get_user_by('email', $this->eventbrite_user_email);
        $this->eventbrite_user_id = $eventbrite_user->ID;
    }

    public function sync_events() {
        //Remove Past Events
        $this->delete_past_posts();

        $request_url = $this->endpoint . 'users/me/owned_events/?status=live&order_by=start_desc';
        $result = $this->api_request($request_url);

        // Create a post for each event
        if (is_array($result->events) || is_object($result->events)) {
            foreach ($result->events as $event) {
                $this->post_factory($event);
            }
        }

        return True;
    }

    private function already_exists($id) {
        $args = array(
            'post_type' => $this->post_type,
            'meta_key' => 'eventbrite_id',
            'meta_value' => $id
        );
        $search = new WP_Query( $args );

        if($search->have_posts()) {
            return true;
        }

        return false;
    }

    private function api_request($request_url) {
        // This array is used to authenticate the request.
        $options = array(
            'http' => array(
              'method' => 'GET',
              'header'=> "Authorization: Bearer " . $this->token
            )
          );

          // Call the URL and get the data.
          $response = @file_get_contents($request_url, false, stream_context_create($options));

          // Return it as arrays/objects.
          return json_decode($response);
    }

    private function post_factory($event) {

        if (!$this->already_exists($event->id)) {
            $this->create_post($event);
        } else {
            $this->update_post($event);
        }
    }

    private function create_post($event) {
        // Build Metadata object
        $metaData = array(
            'eventbrite_description' => strip_tags($event->description->html),
            'eventbrite_id' => $event->id,
            'eventbrite_url' => $event->url,
            'eventbrite_start' => $this->clean_timestamp($event->start->utc),
            'eventbrite_end' => $this->clean_timestamp($event->end->utc),
            'eventbrite_created' => $event->created,
            'eventbrite_changed' => $event->changed,
            'eventbrite_capacity' => $event->capacity,
            'eventbrite_status' => $event->status,
            'eventbrite_currency' => $event->currency
        );

        // Build Post object
        $new_post = array(
            'post_title' => $event->name->text,
            'post_content' => '',
            'post_status' => 'publish',
            'post_date' => date('Y-m-d H:i:s'),
            'post_author' => $this->eventbrite_user_id,
            'post_type' => $this->post_type
        );

        // Insert post
        $post_id = wp_insert_post($new_post);


        if ($post_id != 0) {
            // Add metadata to post
            add_post_meta($post_id, 'request_url', get_site_url().'/wp-admin/post.php?post='.$post_id.'&action=edit');
            foreach ($metaData as $key => $val) {
                add_post_meta($post_id, $key, esc_attr($val));
            }
        }

    }

    private function update_post($event) {
        $args = array(
            'post_type' => $this->post_type,
            'meta_key' => 'eventbrite_id',
            'meta_value' => $event->id
        );
        $search = new WP_Query( $args );
        if ( $search->have_posts() ) {
            $old_post = $search->posts[0];

            $new_post = array(
                'ID'           => $old_post->ID,
                'post_title'   => $event->name->text
            );

            $post_id = wp_update_post($new_post);
            update_post_meta($post_id, 'eventbrite_description', strip_tags($event->description->html));
            update_post_meta($post_id, 'eventbrite_start', $this->clean_timestamp($event->start->utc));
            update_post_meta($post_id, 'eventbrite_end', $this->clean_timestamp($event->end->utc));
            update_post_meta($post_id, 'eventbrite_changed', $event->changed);
            update_post_meta($post_id, 'eventbrite_capacity', $event->capacity);
            update_post_meta($post_id, 'eventbrite_status', $event->status);
            update_post_meta($post_id, 'eventbrite_currency', $event->currency);
        }

    }

    private function delete_past_posts() {
        $query_args = array(
            'post_type' => $this->post_type,
            'posts_per_page' => -1,
            'meta_query' => array(
            array(
                    'key' => 'eventbrite_end',
                    'value' => date('Y-m-d H:i:s'),
                    'compare' => '<',
                    'type' => 'date'
                )
            )
        );
        $query = new WP_Query( $query_args );


        if ( $query->have_posts() ) :
            while ( $query->have_posts() ) :
                $query->the_post();
                wp_delete_post( $query->post->ID, true );
            endwhile;
        endif;

        return true;
    }

    private function clean_timestamp($timestamp) {
        $string = str_replace('T', ' ', $timestamp);
        $string = str_replace('Z', ' ', $string);
        return $string;
    }

}

// Create Post Type
function create_events_post_type() {
    register_post_type( 'events',
        array(
            'labels' => array(
                'name' => 'Events',
                'singular_name' => 'Event',
                'add_new' => 'Add New',
                'add_new_item' => 'Add New Event',
                'edit' => 'Edit',
                'edit_item' => 'Edit Event',
                'new_item' => 'New Event',
                'view' => 'View',
                'view_item' => 'View Event',
                'search_items' => 'Search Events',
                'not_found' => 'No Events found',
                'not_found_in_trash' => 'No Events found in Trash',
                'parent' => 'Parent Event'
            ),

            'public' => true,
            'menu_position' => 6,
            'supports' => array( 'title' ),
            'taxonomies' => array( '' ),
            'menu_icon' => 'dashicons-tickets-alt',
            'has_archive' => true,
            'rewrite' => array('slug' => 'events')
        )
    );
}
add_action( 'init', 'create_events_post_type' );

// Add Meta Boxes to Post edit page
function add_events_metaboxes() {
    add_meta_box('wpt_events_location', 'Event Details', 'wpt_events_location', 'events', 'normal', 'default');
}
function wpt_events_location() {
    global $post;

    ?>
    <h2>Eventbrite Link</h2>
    <a href="<?php echo esc_html( get_post_meta( $post->ID, 'eventbrite_url', true ) ); ?>" target="_blank"><?php echo esc_html( get_post_meta( $post->ID, 'eventbrite_url', true ) ); ?></a>
    <h2>Start Date/Time</h2>
    <span><?php echo esc_html( format_datetime( get_post_meta( $post->ID, 'eventbrite_start', true ) ) ); ?></span>
    <h2>End Date/Time</h2>
    <span><?php echo esc_html( format_datetime( get_post_meta( $post->ID, 'eventbrite_end', true ) ) ); ?></span>
    <h2>Event Description</h2>
    <span><?php echo esc_html( get_post_meta( $post->ID, 'eventbrite_description', true ) ); ?></span>
    <?php
}
add_action( 'add_meta_boxes', 'add_events_metaboxes' );

// Admin Dashboard Widget
add_action( 'wp_dashboard_setup', 'register_eventbrite_sync_dashboard_widget' );
function register_eventbrite_sync_dashboard_widget() {
    global $wp_meta_boxes;

    wp_add_dashboard_widget(
        'eventbrite_sync_dashboard_widget',
        'Eventbrite Events Manager',
        'eventbrite_sync_dashboard_widget_display'
    );

    $dashboard = $wp_meta_boxes['dashboard']['normal']['core'];

    $my_widget = array( 'eventbrite_sync_dashboard_widget' => $dashboard['eventbrite_sync_dashboard_widget'] );
    unset( $dashboard['eventbrite_sync_dashboard_widget'] );

    $sorted_dashboard = array_merge( $my_widget, $dashboard );
    $wp_meta_boxes['dashboard']['normal']['core'] = $sorted_dashboard;

}

function eventbrite_sync_dashboard_widget_display() {
    ?>
    <p>Click the button below to manually sync events from Eventbrite.</p>
    <a class="button button-primary button-hero" href="/api-sync-events">Sync Events</a>
    <?php
}

function eventbrite_custom_post_status(){
    register_post_status( 'unpublished', array(
        'label'                     => _x( 'Unpublished', 'events' ),
        'public'                    => false,
        'exclude_from_search'       => true,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop( 'Unpublished <span class="count">(%s)</span>', 'Unpublished <span class="count">(%s)</span>' ),
    ) );
}
add_action( 'init', 'eventbrite_custom_post_status' );

add_action('admin_footer-post.php', 'eventbrite_append_post_status_list');
function eventbrite_append_post_status_list(){
     global $post;
     $complete = '';
     $label = '';
     if($post->post_type == 'events'){
          if($post->post_status == 'unpublished'){
               $complete = ' selected="selected"';
               $label = '<span id="post-status-display"> Unpublished</span>';
          }
          echo '
          <script>
          jQuery(document).ready(function($){
               $("select#post_status").append("<option value=\"unpublished\" '.$complete.'>Unpublished</option>");
               $(".misc-pub-section label").append("'.$label.'");
          });
          </script>
          ';
     }
}

function eventbrite_display_archive_state( $states ) {
     global $post;
     $arg = get_query_var( 'post_status' );
     if($arg != 'unpublished'){
          if($post->post_status == 'unpublished'){
               return array('Unpublished');
          }
     }
    return $states;
}
add_filter( 'display_post_states', 'eventbrite_display_archive_state' );

/* Adding custom post status to Bulk and Quick Edit boxes: Status dropdown */

function eventbrite_append_post_status_bulk_edit() {
    echo '<script>jQuery(document).ready(function($){$(".inline-edit-status select ").append("<option value=\"unpublished\">Unpublished</option>");});</script>';
}
add_action( 'admin_footer-edit.php', 'eventbrite_append_post_status_bulk_edit' );
?>
