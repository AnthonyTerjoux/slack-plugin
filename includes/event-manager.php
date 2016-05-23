<?php

class WP_Slack_Event_Manager {

	/**
	 * @var WP_Slack_Plugin
	 */
	private $plugin;

	public function __construct( WP_Slack_Plugin $plugin ) {
		$this->plugin = $plugin;

		$this->dispatch_events();
	}

	private function dispatch_events() {

		$events = $this->get_events();

		// Get all integration settings.
		// @todo Adds get_posts method into post type
		// that caches the results.
		$integrations = get_posts( array(
			'post_type'      => $this->plugin->post_type->name,
			'nopaging'       => true,
			'posts_per_page' => -1,
		) );

		foreach ( $integrations as $integration ) {
			$setting = get_post_meta( $integration->ID, 'slack_integration_setting', true );

			// Skip if inactive.
			if ( empty( $setting['active'] ) ) {
				continue;
			}
			if ( ! $setting['active'] ) {
				continue;
			}

			if ( empty( $setting['events'] ) ) {
				continue;
			}

			// For each checked event calls the callback, that's,
			// hooking into event's action-name to let notifier
			// deliver notification based on current integration
			// setting.
			foreach ( $setting['events'] as $event => $is_enabled ) {
				if ( ! empty( $events[ $event ] ) && $is_enabled ) {
					$this->notifiy_via_action( $events[ $event ], $setting );
				}
			}

		}
	}

	/**
	 * Get list of events. There's filter `slack_get_events`
	 * to extend available events that can be notified to
	 * Slack.
	 */
	public function get_events() {
		return apply_filters( 'slack_get_events', array(
			'post_published' => array(
				'action'      => 'transition_post_status',
				'description' => __( 'When a post is published', 'slack' ),
				'default'     => true,
				'message'     => function( $new_status, $old_status, $post ) {
					$notified_post_types = apply_filters( 'slack_event_transition_post_status_post_types', array(
						'post',
					) );

					if ( ! in_array( $post->post_type, $notified_post_types ) ) {
						return false;
					}

					if ( 'publish' !== $old_status && 'publish' === $new_status ) {
						$excerpt = has_excerpt( $post->ID ) ?
							apply_filters( 'get_the_excerpt', $post->post_excerpt )
							:
							wp_trim_words( strip_shortcodes( $post->post_content ), 55, '&hellip;' );

						return sprintf(
							'New post published: *<%1$s|%2$s>* by *%3$s*' . "\n" .
							'> %4$s',

							get_permalink( $post->ID ),
							get_the_title( $post->ID ),
							get_the_author_meta( 'display_name', $post->post_author ),
							$excerpt
						);
					}
				},
			),

			'post_pending_review' => array(
				'action'      => 'transition_post_status',
				'description' => __( 'When a post needs review', 'slack' ),
				'default'     => false,
				'message'     => function( $new_status, $old_status, $post ) {
					$notified_post_types = apply_filters( 'slack_event_transition_post_status_post_types', array(
						'post',
					) );

					if ( ! in_array( $post->post_type, $notified_post_types ) ) {
						return false;
					}

					if ( 'pending' !== $old_status && 'pending' === $new_status ) {
						$excerpt = has_excerpt( $post->ID ) ?
							apply_filters( 'get_the_excerpt', $post->post_excerpt )
							:
							wp_trim_words( strip_shortcodes( $post->post_content ), 55, '&hellip;' );

						return sprintf(
							'New post needs review: *<%1$s|%2$s>* by *%3$s*' . "\n" .
							'> %4$s',

							admin_url( sprintf( 'post.php?post=%d&action=edit', $post->ID ) ),
							get_the_title( $post->ID ),
							get_the_author_meta( 'display_name', $post->post_author ),
							$excerpt
						);
					}
				},
			),

			'new_comment' => array(
				'action'      => 'wp_insert_comment',
				'priority'    => 999,
				'description' => __( 'When there is a new comment', 'slack' ),
				'default'     => false,
				'message'     => function( $comment_id, $comment ) {
					$comment = is_object( $comment ) ? $comment : get_comment( absint( $comment ) );
					$post_id = $comment->comment_post_ID;

					$notified_post_types = apply_filters( 'slack_event_wp_insert_comment_post_types', array(
						'post',
					) );

					if ( ! in_array( get_post_type( $post_id ), $notified_post_types ) ) {
						return false;
					}

					$post_title     = get_the_title( $post_id );
					$comment_status = wp_get_comment_status( $comment_id );

					// Ignore spam.
					if ( 'spam' === $comment_status ) {
						return false;
					}

					return sprintf(
						'<%1$s|New comment> by *%2$s* on *<%3$s|%4$s>* (_%5$s_)' . "\n" .
						'>%6$s',

						admin_url( "comment.php?c=$comment_id&action=editcomment" ),
						$comment->comment_author,
						get_permalink( $post_id ),
						$post_title,
						$comment_status,
						preg_replace( "/\n/", "\n>", get_comment_text( $comment_id ) )
					);
				},
			),
		) );
	}

	public function notifiy_via_action( array $event, array $setting ) {
		$notifier = $this->plugin->notifier;

		$priority = 10;
		if ( ! empty( $event['priority'] ) ) {
			$priority = intval( $event['priority'] );
		}

		$callback = function() use( $event, $setting, $notifier ) {
			$message = '';
			$attachments = '';
                        $icon_url = '';
			if ( is_string( $event['message'] ) ) {
				$message = $event['message'];
			} else if ( is_callable( $event['message'] ) ) {
				$message = call_user_func_array( $event['message'], func_get_args() );
			}

                        if ( is_string( $event['attachments'] ) ) {
				$attachments = $event['attachments'];
			} else if ( is_callable( $event['attachments'] ) ) {
				$attachments = call_user_func_array( $event['attachments'], func_get_args() );
			}
                        
                        if ( is_string( $event['icon_url'] ) ) {
				$icon_url = $event['icon_url'];
			} else if ( is_callable( $event['icon_url'] ) ) {
				$icon_url = call_user_func_array( $event['icon_url'], func_get_args() );
			}

			if ( ! empty( $message ) ) {
				$setting = wp_parse_args(
					array(
						'text' => $message,
                                                'attachments' => $attachments,
                                                'icon_url'    => $icon_url,
					),
					$setting
				);

				$notifier->notify( new WP_Slack_Event_Payload( $setting ) );
			}
		};
		add_action( $event['action'], $callback, $priority, 5 );
	}
}

//Add the event 'award achievement' to the list of pre-defined events
//When a badge is awarded a message containing an attachment (text+image) is sent
//to slack, empty fields are kept for further modifications
add_filter( 'slack_get_events', function( $events ) {
    $events['award_achievement'] = array(
        'action'      => 'badgeos_award_achievement',
        'priority'    => 10,
        'default'     => true,
        'description' => __( 'When user earns an achievement', 'slack' ),
        'message'     => function($user_id = 0, $achievement_id = 0, $trigger = '') {
            $type = get_post_type($achievement_id);
            if($type == 'step') {
                return;
            }
            return ' ';
        },
        'attachments'   => function($user_id = 0, $achievement_id = 0, $trigger = '') {
            $achievement_title = get_the_title($achievement_id);
            $user = get_user_by('id', $user_id);
            $type = get_post_type($achievement_id);
            if($type == 'nomination' || $type == 'submission' || $type == 'badges') {
                $type = 'badge';
            }
            $not_escaped = array('<', '>', '&nbsp;', '&laquo;', '&raquo;');
            $new_str = array('&lt;', '&gt;', ' ', '<<', '>>');
            $achievement_title = str_replace($not_escaped, $new_str, $achievement_title);
            $link = str_replace($not_escaped, $new_str, get_permalink($achievement_id));
            $text = '_'.$user->display_name.'_ earned the '.$type.' <'.$link.'|'.$achievement_title. '>';
            return array(   'fallback' => 'Badge award',
                            'color'    => '#36a64f',
                            'pretext'  => '',
                            'author_name' => '',
                            'author_link' => '',
                            'author_icon' => '',
                            'title'    =>  'New achievement awarded !',
                            'title_link' => '',
                            'text' => $text,
                            'mrkdwn_in' => array("text"),
                            'image_url' => '',
                            'thumb_url' => wp_get_attachment_image_src( get_post_thumbnail_id( $achievement_id), array('100', '100'))[0],
                        );
        },   
        'icon_url'   => function($user_id = 0, $achievement_id = 0, $trigger = '') {
                       return '';
        },
				
    );
    return $events;
} );