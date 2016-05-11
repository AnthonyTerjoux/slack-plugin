<?php

class WP_Slack_Event_Payload {

	/**
	 * @var array
	 */
	private $setting;

	public function __construct( array $setting ) {
		$this->setting = $setting;
	}

	public function get_url() {
		return $this->setting['service_url'];
	}

	public function toJSON() {
		return json_encode( array(
			'channel'      => $this->setting['channel'],
			'username'     => $this->setting['username'],
			'text'         => $this->setting['text'],
			'icon_emoji'   => $this->setting['icon_emoji'],
                        'icon_url'     => $this->setting['icon_url'],
                        'attachments'  => array($this->setting['attachments']),
			/**
			 * @todo icon_emoji with ability to select it in setting.
			 */
		) );
	}
}
