<?php

//------------------------------------------------------------------------------
// Twitoaster post replies (Twitter Comments)
//------------------------------------------------------------------------------

	function twitoaster_post_replies_twitter_comments()
	{
		global $twitoaster_options, $twitoaster_meta, $post;

		if (($twitoaster_meta['expire'] < current_time('timestamp', true)) AND (comments_open($post->ID)) AND (get_post_time('G', true, $post)) AND (get_post_time('G', true, $post) > 0) AND (get_post_time('G', true, $post) < (current_time('timestamp', true) - 60)) AND ((empty($twitoaster_meta['working'])) OR ($twitoaster_meta['working'] < (current_time('timestamp', true) - 60))))
		{
			// Twitoaster Meta Lock
			$twitoaster_meta['working'] = current_time('timestamp', true);
			twitoaster_post_replies_update_meta('_twitoaster_post_replies', $post->ID, $twitoaster_meta);

			// Twitoaster API Request
			$twitoaster_api_result = twitoaster_api_request('GET', 'conversation/search', array('query' => get_permalink(), 'user_id' => $twitoaster_options['user_id'], 'in_urls_only' => true, 'in_titles_only' => true, 'show_replies' => true));

			// Twitoaster API Error Handling
		 	if ($twitoaster_api_result['error']) {
				if ($twitoaster_api_result['error']['user_issue']) {
					$twitoaster_options = twitoaster_options($twitoaster_options, true);
					update_option('twitoaster_options', $twitoaster_options);
				}
				else { $twitoaster_meta['expire'] = (current_time('timestamp', true) + $twitoaster_options['api_timing']['instant']); }
			}

			// Twitoaster API Data Processing
			else {
				if (get_post_time('G', true, $post) > (current_time('timestamp', true) - 7200)) { $expire = $twitoaster_options['api_timing']['short']; } /* < 2h */
				else if (get_post_time('G', true, $post) > (current_time('timestamp', true) - 172800)) { $expire = $twitoaster_options['api_timing']['normal']; } /* 2h - 48h */
				else if (get_post_time('G', true, $post) > (current_time('timestamp', true) - 1296000)) { $expire = $twitoaster_options['api_timing']['long']; } /* 48h - 15j */
				else { $expire = $twitoaster_options['api_timing']['huge']; } /* > 15j */

				$twitoaster_meta_new = array('expire' => (current_time('timestamp', true) + $expire), 'content' => array());

				foreach ($twitoaster_api_result['data'] AS $conversation) {

					// Init
					$comment_parent_default = 0;

					// Threads
					if ($twitoaster_options['post_replies']['show_threads'] == 'yes') {
						if (!(array_key_exists($conversation->thread->id, $twitoaster_meta['content']))) {
							if ($twitoaster_meta_comment = twitoaster_post_replies_comment_insert($post, 0, $conversation->thread)) {
								$twitoaster_meta_new['content'][$conversation->thread->id] = $twitoaster_meta_comment;
								$comment_parent_default = $twitoaster_meta_comment['comment_id'];
							}
							else { break; } /* Failed to insert comment */
						}
						else {
							$twitoaster_meta_new['content'][$conversation->thread->id] = $twitoaster_meta['content'][$conversation->thread->id];
							$comment_parent_default = $twitoaster_meta['content'][$conversation->thread->id]['comment_id'];
						}
					}

					// Replies
					if (isset($conversation->replies)) {
						foreach($conversation->replies AS $reply) {
							if (!(array_key_exists($reply->id, $twitoaster_meta['content']))) {
								if (array_key_exists($reply->in_reply_to_status_id, $twitoaster_meta_new['content'])) { $comment_parent = $twitoaster_meta_new['content'][$reply->in_reply_to_status_id]['comment_id']; }
								else if (array_key_exists($reply->in_reply_to_status_id, $twitoaster_meta['content'])) { $comment_parent = $twitoaster_meta['content'][$reply->in_reply_to_status_id]['comment_id']; }
								else { $comment_parent = $comment_parent_default; }
								if ($twitoaster_meta_comment = twitoaster_post_replies_comment_insert($post, $comment_parent, $reply)) {
									$twitoaster_meta_new['content'][$reply->id] = $twitoaster_meta_comment;
								}
								else { break; } /* Failed to insert comment */
							}
							else {
								$twitoaster_meta_new['content'][$reply->id] = $twitoaster_meta['content'][$reply->id];
							}
						}
					}
				}

				$twitoaster_meta = $twitoaster_meta_new;
			}

			// Twitoaster Meta Update
			$twitoaster_meta['working'] = false;
			twitoaster_post_replies_update_meta('_twitoaster_post_replies', $post->ID, $twitoaster_meta);
		}
	}

	function twitoaster_post_replies_comment_insert($post, $comment_parent, $twitoaster_data) {
		global $twitoaster_options;

		$data = array(
			'comment_post_ID' => $post->ID,
			'comment_author' => $twitoaster_data->user->screen_name,
			'comment_author_email' => 'wp-plugin@twitoaster.com',
			'comment_author_url' => ($twitoaster_data->user->url == false) ? '' : $twitoaster_data->user->url,
			'comment_author_IP' => '87.98.250.193',
			'comment_date' => date("Y-m-d H:i:s", $twitoaster_data->created_at->unix_timestamp),
			'comment_date_gmt' => gmdate("Y-m-d H:i:s", $twitoaster_data->created_at->unix_timestamp),
			'comment_content' => $twitoaster_data->content,
			'comment_approved' => ($twitoaster_options['post_replies']['moderation'] == 'yes') ? 0 : 1,
			'comment_agent' => 'Twitoaster||' . $twitoaster_data->id,
			'comment_type' => 'comment',
			'comment_parent' => $comment_parent
		);

		if (($comment_id = wp_insert_comment($data)) AND (is_numeric($comment_id)) AND ($comment_id > 0)) {
			if ($data['comment_approved'] == '0') { wp_notify_moderator($comment_id); }
			if ((get_option('comments_notify')) AND ($data['comment_approved']) AND ($post->post_author != $twitoaster_options['user_id'])) { wp_notify_postauthor($comment_id, $data['comment_type']); }
			return array('comment_id' => $comment_id, 'avatar' => $twitoaster_data->user->profile_image_url, 'url' => $twitoaster_data->url);
		}
		else { return false; }
	}

	function twitoaster_post_replies_get_meta($meta_key, $post_ID) {
		if ((isset($post_ID)) AND (is_numeric($post_ID)) AND ($post_ID > 0)) { $meta = get_post_meta($post_ID, $meta_key, true); }
		else { $meta = ""; }

		if (($meta != "") AND (is_array($meta))) { return $meta; }
		else if (($meta != "") AND (is_serialized($meta))) { return unserialize($meta); }
		else { return array('expire' => 1, 'content' => array(), 'working' => false); }
	}

	function twitoaster_post_replies_update_meta($meta_key, $post_ID, $meta) {
		if ((isset($post_ID)) AND (is_numeric($post_ID)) AND ($post_ID > 0)) {
			if (get_post_meta($post_ID, $meta_key, true) != "") { update_post_meta($post_ID, $meta_key, serialize($meta)); }
			else { add_post_meta($post_ID, $meta_key, serialize($meta), true); }
		}
	}

	function twitoaster_post_replies_comment_meta($comment) {
		global $twitoaster_meta;
		$twitoaster_meta_comment_id = str_replace('Twitoaster||', '', $comment->comment_agent);
		if ((is_numeric($twitoaster_meta_comment_id)) AND (isset($twitoaster_meta['content'][$twitoaster_meta_comment_id]))) { return array('avatar' => $twitoaster_meta['content'][$twitoaster_meta_comment_id]['avatar'], 'url' => $twitoaster_meta['content'][$twitoaster_meta_comment_id]['url']); }
		else { return false; }
	}

	function twitoaster_post_replies_comment_avatar($avatar, $comment = false, $size = '32', $default = '', $alt = false) {
		if ($twitoaster_meta_comment = twitoaster_post_replies_comment_meta($comment)) {
			$twitter_avatar_url = ($size > 48) ? str_replace('_normal.', '_bigger.', $twitoaster_meta_comment['avatar']) : $twitoaster_meta_comment['avatar'];
			return '<img alt="' . $comment->comment_author . '" src="' . $twitter_avatar_url . '" class="avatar avatar-' . $size . ' photo" height="' . $size . '" width="' . $size . '" />';
		} else { return $avatar; }
	}

	function twitoaster_post_replies_comment_content($text) {
		global $comment;
		if ($twitoaster_meta_comment = twitoaster_post_replies_comment_meta($comment)) { return $text . ' <br /><span class="twitoaster">via <strong><a href="' . htmlentities($twitoaster_meta_comment['url']) . '" title="' . htmlentities($comment->comment_content) . '">Twitoaster</a></strong></span>'; }
		else { return $text; }
	}

	function twitoaster_post_replies_reset() {
		$allposts = get_posts('numberposts=0&post_type=post&post_status=');
		foreach( $allposts as $postinfo) {
			$twitoaster_meta = twitoaster_post_replies_get_meta('_twitoaster_post_replies', $postinfo->ID);
			foreach ($twitoaster_meta['content'] AS $twitoaster_meta_comment_id => $twitoaster_meta_comment) {
				if (is_numeric($twitoaster_meta_comment_id)) { wp_delete_comment($twitoaster_meta_comment['comment_id']); }
			}
			delete_post_meta($postinfo->ID, '_twitoaster_post_replies');
		}
	}

	function twitoaster_post_replies() {
		global $twitoaster_options, $twitoaster_meta, $post;
		if (is_single()) {
			// Init
			$twitoaster_meta = twitoaster_post_replies_get_meta('_twitoaster_post_replies', $post->ID);
			add_filter('comment_text', 'twitoaster_post_replies_comment_content', 29);
			add_filter('get_avatar', 'twitoaster_post_replies_comment_avatar', 10, 5);

			// Post Replies
			if (($twitoaster_options['api_key'] != '') AND ($twitoaster_options['post_replies']['twitter_comments'] == 'yes')) {
				twitoaster_post_replies_twitter_comments();
			}
		}
	}

	add_action('loop_start', 'twitoaster_post_replies');


//------------------------------------------------------------------------------
// Twitoaster post replies Auto Threading (Tweet Post)
//------------------------------------------------------------------------------

	function twitoaster_post_replies_auto_thread($post_ID)
	{
		global $twitoaster_options;
		$post = get_post($post_ID);
		$twitoaster_meta_thread = twitoaster_post_replies_get_meta('_twitoaster_post_auto_thread', $post->ID);

		// Needs Auto Threading
		if (($post->post_status == 'publish') AND ($twitoaster_options['post_replies']['twitter_comments'] == 'yes') AND (empty($twitoaster_meta_thread['content'])) AND ($twitoaster_meta_thread['post_replies']['auto_threads'] == 'yes') AND ((empty($twitoaster_meta_thread['working'])) OR ($twitoaster_meta_thread['working'] < (current_time('timestamp', true) - 60))))
		{
			// Twitoaster Meta Thread Lock
			$twitoaster_meta_thread['working'] = current_time('timestamp', true);
			twitoaster_post_replies_update_meta('_twitoaster_post_auto_thread', $post->ID, $twitoaster_meta_thread);

			// Status Format
			$status = $twitoaster_meta_thread['post_replies']['auto_threads_format'];
			$status = preg_replace('`((?:https?)://\S+[[:alnum:]]/?)`si', ' ', $status);

			// Status PostTitle
			$status = preg_replace('`%postname%`i', $post->post_title, $status);

			// Status URL (1/2)
			if (preg_replace('`%url%`i', '', $status) == $status) { $status .= ' ' . '%url%'; }

			// Status Compacting...
			$status = trim(preg_replace('/\s{2,}/', ' ', $status));
			if ((function_exists('mb_strlen')) AND (function_exists('mb_substr')) AND (function_exists('mb_strrpos'))) {
				if (mb_strlen(($status) - 5) > 117) {
					$status = mb_substr($status, 0, 117);
					$space = mb_strrpos($status, " ");
					$status = mb_substr($status, 0, $space);
				}
			}
			else {
				if (strlen(($status) - 5) > 117) {
					$status = substr($status, 0, 117);
					$space = strrpos($status, " ");
					$status = substr($status, 0, $space);
				}
			}

			// Status URL (2/2)
			if (preg_replace('`%url%`i', '', $status) == $status) { $status .= ' ' . '%url%'; }
			$status = preg_replace('`%url%`i', get_permalink($post->ID), $status);

			// Twitoaster API Request
			$twitoaster_api_result = twitoaster_api_request('POST', 'user/update', array('api_key' => $twitoaster_options['api_key'], 'extended' => false, 'status' => $status), 20);

			// Twitoaster API Error Handling
			if ($twitoaster_api_result['error']) {
				if ($twitoaster_api_result['error']['user_issue']) {
					$twitoaster_options = twitoaster_options($twitoaster_options, true);
					update_option('twitoaster_options', $twitoaster_options);
				}
			}

			// Twitoaster API Data Processing
			else {
				$twitoaster_meta_thread['expire'] = current_time('timestamp', true);
				$twitoaster_meta_thread['content'] = array(
					'url' => $twitoaster_api_result['data']->thread->url,
					'title' => $twitoaster_api_result['data']->thread->title,
					'user' => $twitoaster_api_result['data']->thread->user->screen_name,
					'user_url' => $twitoaster_api_result['data']->thread->user->url
				);
			}

			// Twitoaster Meta Thread Update
			$twitoaster_meta_thread['working'] = false;
			twitoaster_post_replies_update_meta('_twitoaster_post_auto_thread', $post->ID, $twitoaster_meta_thread);
		}
	}

	function twitoaster_add_auto_thread_box() {
		if (function_exists('add_meta_box')) {
			add_meta_box('twitoaster_auto_thread', 'Tweet Post', 'twitoaster_inner_auto_thread_box', 'post', 'normal', 'high');
		} else {
			add_action('dbx_post_advanced', 'twitoaster_old_auto_thread_box');
		}
	}

	function twitoaster_inner_auto_thread_box() {
		global $twitoaster_options, $post;
		$currentoptions = $twitoaster_options;
		$twitoaster_meta_thread = twitoaster_post_replies_get_meta('_twitoaster_post_auto_thread', $post->ID);
		if ((!(empty($twitoaster_meta_thread['post_replies']))) AND ($currentoptions['post_replies']['twitter_comments'] == 'yes')) {
			$currentoptions['post_replies']['auto_threads'] = $twitoaster_meta_thread['post_replies']['auto_threads'];
			$currentoptions['post_replies']['auto_threads_format'] = $twitoaster_meta_thread['post_replies']['auto_threads_format'];
		}

		echo '<input type="hidden" name="twitoaster-noncename" id="twitoaster-noncename" value="' . wp_create_nonce( plugin_basename(__FILE__) ) . '" />';

		if (empty($twitoaster_meta_thread['content'])) {
		?>
			<p>
				<label>
					<input name="twitoaster-post-replies-auto-threads" id="twitoaster-post-replies-auto-threads" value="yes" type="checkbox"<?php checked($currentoptions['post_replies']['auto_threads'], 'yes'); ?><?php if (!($currentoptions['post_replies']['twitter_comments'] == 'yes')) { echo ' disabled="disabled"'; }?> />&nbsp;Tweet this Post once published.
					<span class="description">&nbsp;<?php if (!($currentoptions['post_replies']['twitter_comments'] == 'yes')) { ?>Enable <a href="<?php echo get_bloginfo('wpurl'); ?>/wp-admin/options-general.php?page=twitoaster" target="_blank">Twitter Comments</a> to use this option.<?php } else { ?><strong>Recommended</strong>. Note you can define your tweet content above.<?php } ?></span>
				</label>
			</p>
			<p>
				<label>
					<input name="twitoaster-post-replies-auto-threads-format" id="twitoaster-post-replies-auto-threads-format" value="<?php echo htmlspecialchars($currentoptions['post_replies']['auto_threads_format']); ?>" type="text" class="code" style="width:300px;"<?php if (!($currentoptions['post_replies']['twitter_comments'] == 'yes')) { echo ' disabled="disabled"'; }?> />
					<span class="description">&nbsp;<?php if (!($currentoptions['post_replies']['twitter_comments'] == 'yes')) { ?>Enable <a href="<?php echo get_bloginfo('wpurl'); ?>/wp-admin/options-general.php?page=twitoaster" target="_blank">Twitter Comments</a> to use this option.<?php } else { ?>%url% and %postname% tags supported.<?php } ?></span>
				</label>
			</p>
		<?php } else { ?>
			<p>
				<?php if ($twitoaster_meta_thread['expire'] > $twitoaster_options['install_time']) { ?>
					This post has already been tweeted by <a href="<?php echo $twitoaster_meta_thread['content']['user_url']; ?>" target="_blank"><?php echo $twitoaster_meta_thread['content']['user']; ?></a>.
					<br />Twitoaster conversation: <a href="<?php echo $twitoaster_meta_thread['content']['url']; ?>" target="_blank"><?php echo $twitoaster_meta_thread['content']['title']; ?></a>
				<?php } else { ?>
					This post has already been tweeted.
				<?php } ?>
			</p>
		<?php
		}
	}

	function twitoaster_old_auto_thread_box() {
		echo '<div class="dbx-b-ox-wrapper">' . "\n";
		echo '<fieldset id="twitoaster_fieldsetid" class="dbx-box">' . "\n";
		echo '<div class="dbx-h-andle-wrapper"><h3 class="dbx-handle">Tweet Post</h3></div>';
		echo '<div class="dbx-c-ontent-wrapper"><div class="dbx-content">';
		twitoaster_inner_auto_thread_box();
		echo "</div></div></fieldset></div>\n";
	}

	function twitoaster_save_postdata($post_ID) {
		global $twitoaster_options;
		$currentoptions = $twitoaster_options;
		$twitoaster_meta_thread = twitoaster_post_replies_get_meta('_twitoaster_post_auto_thread', $post_ID);
		if ((!(empty($twitoaster_meta_thread['post_replies']))) AND ($currentoptions['post_replies']['twitter_comments'] == 'yes')) {
			$currentoptions['post_replies']['auto_threads'] = $twitoaster_meta_thread['post_replies']['auto_threads'];
			$currentoptions['post_replies']['auto_threads_format'] = $twitoaster_meta_thread['post_replies']['auto_threads_format'];
		}

		if ((empty($twitoaster_meta_thread['content'])) AND ($currentoptions['post_replies']['twitter_comments'] == 'yes'))
		{
			if (!(@wp_verify_nonce($_POST['twitoaster-noncename'], plugin_basename(__FILE__)))) { return $post_ID; }
			if ((defined('DOING_AUTOSAVE')) AND (DOING_AUTOSAVE)) { return $post_ID; }
			if ('page' == $_POST['post_type']) { return $post_ID; } else { if (!(current_user_can('edit_post', $post_ID))) { return $post_ID; } }

			if (isset($_POST['twitoaster-post-replies-auto-threads'])) { $currentoptions['post_replies']['auto_threads'] = 'yes'; }
			else { $currentoptions['post_replies']['auto_threads'] = 'no'; }
			if (isset($_POST['twitoaster-post-replies-auto-threads-format'])) {
				if (!(empty($_POST['twitoaster-post-replies-auto-threads-format']))) { $currentoptions['post_replies']['auto_threads_format'] = stripslashes($_POST['twitoaster-post-replies-auto-threads-format']); }
				else { $currentoptions['post_replies']['auto_threads_format'] = ''; }
			}

			$currentoptions = twitoaster_options($currentoptions);
			$twitoaster_meta_thread['post_replies']['auto_threads'] = $currentoptions['post_replies']['auto_threads'];
			$twitoaster_meta_thread['post_replies']['auto_threads_format'] = $currentoptions['post_replies']['auto_threads_format'];
			twitoaster_post_replies_update_meta('_twitoaster_post_auto_thread', $post_ID, $twitoaster_meta_thread);
			twitoaster_post_replies_auto_thread($post_ID);
			return $twitoaster_meta_thread;
		}

		else {
			return $post_ID;
		}
	}

	add_action('admin_menu', 'twitoaster_add_auto_thread_box');
	add_action('save_post', 'twitoaster_save_postdata');

?>
