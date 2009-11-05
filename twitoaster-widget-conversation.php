<?php

//------------------------------------------------------------------------------
// Widget - Twitoaster Conversation
//------------------------------------------------------------------------------

	function widget_twitoaster_conversation($args)
	{
		global $twitoaster_options;
		extract($args);
		$display_content = true;
		$error = false;

		$options = get_option('widget_twitoaster_conversation');
		$content = get_option('widget_twitoaster_conversation_content');
		$currentoptions = widget_twitoaster_conversation_options($options);

		// Twitoaster Plugin Configured
		if ($twitoaster_options['api_key'] != '') {
			if ((!($content)) OR ($content['expire'] < current_time('timestamp', true)))
			{
				// Twitoaster API Request
				$twitoaster_api_result = twitoaster_api_request('GET', 'conversation/user', array('user_id' => $twitoaster_options['user_id']));

				// Twitoaster API Error Handling
				if ($twitoaster_api_result['error']) {
					if ($twitoaster_api_result['error']['user_issue']) {
						$display_content = false;
						$twitoaster_options = twitoaster_options($twitoaster_options, true);
						update_option('twitoaster_options', $twitoaster_options);
					}
					else {
						if (!($content['content'])) { $display_content = false; }
						$content['expire'] = (current_time('timestamp', true) + $twitoaster_options['api_timing']['instant']);
						update_option('widget_twitoaster_conversation_content', $content);
					}
					$error = $twitoaster_api_result['error']['message'];
				}

				// Twitoaster API Data Processing
				else {
					$content_tmp = array();
					foreach ($twitoaster_api_result['data'] AS $conversation) {
						if ((count($content_tmp) < $currentoptions['items']) AND (($currentoptions['hide_no_replies'] == 'no') OR (($currentoptions['hide_no_replies'] == 'yes') AND ($conversation->thread->stats->total_replies > 0)))) {
							$content_tmp[] = '<span class="twitoaster_replies">' . $conversation->thread->stats->total_replies . '</span>' . ' ' . '<a href="' . $conversation->thread->url . '" title="' . $conversation->thread->stats->total_replies . ' ' . (($conversation->thread->stats->total_replies > 1) ? 'replies' : 'reply') . ' from ' . $conversation->thread->stats->user_replies . ' ' . (($conversation->thread->stats->user_replies > 1) ? 'users' : 'user') . ' to this ' . $twitoaster_options['screen_name'] . ' conversation' . '">' . $conversation->thread->title . '</a>';
						}
					}
					$content = array('expire' => (current_time('timestamp', true) + $twitoaster_options['api_timing']['short']), 'content' => $content_tmp);
					update_option('widget_twitoaster_conversation_content', $content);
				}
			}
		}

		// Twitoaster Plugin Configuration Problem
		else {
			$display_content = false;
			$error = 'No Twitoaster API Key found!';
		}

		// Output
		printf("%s Twitoaster Conversations ( %s ) %s \n", '<!--', $twitoaster_options['profile'], '-->');
		if ($display_content) {
			if (count($content['content']) > 0) { $out = '<ul class="twitoaster_widget_conversation"><li>' . implode('</li><li>', $content['content']) . '</li></ul>'; }
			else { $out = '<p>No Twitter conversations found yet.</p>'; }
		}
		else if (current_user_can('manage_options')) { $out = '<p><strong>' . $error . '</strong></p><p>You can fix this problem on the Twitoaster plugin <strong><a href="' . get_bloginfo('wpurl') . '/wp-admin/options-general.php?page=twitoaster">configuration page</a></strong>. You are seeing this warning message because you are an administrator of ' . get_bloginfo('name') . '.</p>'; }
		else { $out = ''; }

		if ($out != '') {
			echo $before_widget;
			echo $before_title . attribute_escape($currentoptions['title']) . $after_title;
			echo $out;
			echo $after_widget;
		}
	}


//------------------------------------------------------------------------------
// Widget - Twitoaster Conversation Config (Admin Page)
//------------------------------------------------------------------------------

	function widget_twitoaster_conversation_control()
	{
		global $twitoaster_options;

		// Twitoaster Plugin Configured
		if ($twitoaster_options['api_key'] != '') {
			$options = $newoptions = get_option('widget_twitoaster_conversation');

			if ( isset($_POST['twitoaster-conversation-submit']) )
			{
				$newoptions['title'] = strip_tags(stripslashes($_POST['twitoaster-conversation-title']));

				if (isset($_POST['twitoaster-conversation-hide-no-replies'])) { $newoptions['hide_no_replies'] = 'yes'; }
				else { $newoptions['hide_no_replies'] = 'no'; }

				$items = stripslashes($_POST['twitoaster-conversation-items']);
				if ($items < 1) { $newoptions['items'] = 1; }
				else if ($items > 10) { $newoptions['items'] = 10; }
				else { $newoptions['items'] = $items; }
			}

			if ($options != $newoptions) {
				$options = $newoptions;
				update_option('widget_twitoaster_conversation', $options);
				delete_option('widget_twitoaster_conversation_content');
			}

			$currentoptions = widget_twitoaster_conversation_options($options);
			printf("%s Twitoaster Conversations Control ( %s ) %s \n", '<!--', $twitoaster_options['profile'], '-->');
			?>
				<p>
					<label for="twitoaster-conversation-title">Title:</label>
					<input class="widefat" id="twitoaster-conversation-title" name="twitoaster-conversation-title" type="text" value="<?php echo attribute_escape($currentoptions['title']); ?>" />
				</p>
				<p>
					<label for="twitoaster-conversation-items">Conversations displayed:</label>
					<select id="twitoaster-conversation-items" name="twitoaster-conversation-items">
						<?php
							for ( $i = 1; $i <= 10; ++$i ) {
								echo "<option value='$i' " . ( $currentoptions['items'] == $i ? "selected='selected'" : '' ) . ">$i</option>";
							}
						?>
					</select>
				</p>
				<p>
					<label><input name="twitoaster-conversation-hide-no-replies" id="twitoaster-conversation-hide-no-replies" value="yes" type="checkbox"<?php checked($currentoptions['hide_no_replies'], 'yes'); ?> /> Hide conversations without replies</label>
				</p>

				<input type="hidden" id="twitoaster-conversation-submit" name="twitoaster-conversation-submit" value="1" />
			<?php
		}

		// Twitoaster Plugin Configuration Problem
		else {
			echo '<p><strong>' . 'No Twitoaster API Key found!' . '</strong></p><p>As an administrator, you can fix this problem on the Twitoaster plugin <strong><a href="' . get_bloginfo('wpurl') . '/wp-admin/options-general.php?page=twitoaster">configuration page</a></strong>.</p>';
		}
	}

	function widget_twitoaster_conversation_options($options) {
		global $twitoaster_options;

		$currentoptions = array();
		$currentoptions['title'] = empty( $options['title'] ) ? '@' . $twitoaster_options['screen_name'] . ' Conversations' : apply_filters('widget_title', $options['title']);
		$currentoptions['hide_no_replies'] = empty( $options['hide_no_replies'] ) ? 'no' : $options['hide_no_replies'];
		if (empty($options['items'])) { $currentoptions['items'] = 5; }
		else if ($options['items'] < 1) { $currentoptions['items'] = 1; }
		else if ($options['items'] > 10) { $currentoptions['items'] = 10; }
		else { $currentoptions['items'] = $options['items']; }

		return $currentoptions;
	}

	function twitoaster_conversation_widget_init() {
		register_sidebar_widget('Twitter Conversations', 'widget_twitoaster_conversation');
		register_widget_control('Twitter Conversations', 'widget_twitoaster_conversation_control');
	}

	add_action("plugins_loaded", "twitoaster_conversation_widget_init");

?>