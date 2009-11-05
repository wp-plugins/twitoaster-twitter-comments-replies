<?php

/*
Plugin Name: Twitoaster - Twitter Conversations (testing)
Plugin URI: http://twitoaster.com/wordpress-plugin/
Description: Automatically retrieve Twitter Replies to your Blog's Posts. These Twitter Replies are handled like Posts Comments. Also bring Twitter Statistics.
Version: 1.2.3
Author: Twitoaster
Author URI: http://twitoaster.com/
*/

//------------------------------------------------------------------------------
// Init
//------------------------------------------------------------------------------

	// Options and Metas
	$twitoaster_options = twitoaster_options(get_option('twitoaster_options'));
	$twitoaster_meta = array();

	// Be sure the API Key is set, as it ensures the user owns the Twitter account linked to his blog
	if (($twitoaster_options['api_key'] == '') AND (!(isset($_POST['submit']))) AND (!(isset($_POST['submit-api-key']))) AND (!(isset($_POST['submit-post-replies']))) AND (!(isset($_POST['submit-post-replies-reset']))) AND ($_SERVER['QUERY_STRING'] != 'page=twitoaster')) {
		function twitoaster_admin_notice() {
			echo '<div id="message" class="updated fade"><p><strong>Twitoaster plugin is almost ready.</strong> You must <a href="' . get_bloginfo('wpurl') . '/wp-admin/options-general.php?page=twitoaster">enter your Twitoaster API Key</a> for it to work.</p></div>';
		}
		add_action( 'admin_notices', 'twitoaster_admin_notice' );
	}

	// Some includes and we're all set
	require_once('twitoaster-post-replies.php');
	require_once('twitoaster-widget-chart.php');
	require_once('twitoaster-widget-conversation.php');


//------------------------------------------------------------------------------
// Twitoaster Options
//------------------------------------------------------------------------------

	function twitoaster_options($options = array(), $reset = false) {
		if (!(is_array($options))) { $options = array(); }
		$currentoptions = array();

		$currentoptions['install_version'] = '1.2.3';
		if (empty($options['install_time'])) { $currentoptions['install_time'] = current_time('timestamp', true); }
		else { $currentoptions['install_time'] = $options['install_time']; }

		if ($reset) {
			$currentoptions['profile'] = 'http://twitoaster.com/';
			$currentoptions['screen_name'] = '';
			$currentoptions['user_nicename'] = '';
			$currentoptions['user_id'] = 0;
			$currentoptions['api_key'] = '';
			delete_option('widget_twitoaster_conversation_content');
		}
		else {
			$currentoptions['profile'] = empty($options['profile']) ? 'http://twitoaster.com/' : $options['profile'];
			$currentoptions['screen_name'] = empty($options['screen_name']) ? '' : $options['screen_name'];
			$currentoptions['user_nicename'] = empty($options['user_nicename']) ? '' : $options['user_nicename'];
			$currentoptions['user_id'] = empty($options['user_id']) ? 0 : $options['user_id'];
			$currentoptions['api_key'] = empty($options['api_key']) ? '' : $options['api_key'];
		}

		if ($currentoptions['api_key'] == '') {
			$currentoptions['post_replies']['twitter_comments'] = 'You need to enter your Twitoaster API Key.';
			$currentoptions['post_replies']['auto_threads'] = 'no';
		}
		else if (function_exists('dsq_loop_start')) {
			$currentoptions['post_replies']['twitter_comments'] = 'You can\'t use the Twitter Comments feature with DISQUS.<br />Please desactivate the DISQUS plugin if you want to use Twitter Comments!';
			$currentoptions['post_replies']['auto_threads'] = 'no';
		}
		else {
			$currentoptions['post_replies']['twitter_comments'] = ((empty($options['post_replies']['twitter_comments'])) OR (!(in_array($options['post_replies']['twitter_comments'], array('yes', 'no'))))) ? 'yes' : $options['post_replies']['twitter_comments'];
			if ($currentoptions['post_replies']['twitter_comments'] == 'yes') { $currentoptions['post_replies']['auto_threads'] = empty($options['post_replies']['auto_threads']) ? 'no' : $options['post_replies']['auto_threads']; }
			else { $currentoptions['post_replies']['auto_threads'] = 'no'; }
		}

		$currentoptions['post_replies']['auto_threads_format'] = ((!(isset($options['post_replies']['auto_threads_format']))) OR (empty($options['post_replies']['auto_threads_format']))) ? '[New Post] %postname% - via @twitoaster %url%' : $options['post_replies']['auto_threads_format'];
		$currentoptions['post_replies']['moderation'] = (!(isset($options['post_replies']['moderation']))) ? 'no' : $options['post_replies']['moderation'];
		$currentoptions['post_replies']['show_threads'] = (!(isset($options['post_replies']['show_threads']))) ? 'yes' : $options['post_replies']['show_threads'];

		$currentoptions['api_timing'] = array('instant' => 300, 'short' => 900, 'normal' => 3600, 'long' => 28800, 'huge' => 259200); /* 5m - 15m - 1h - 8h - 72h */

		return $currentoptions;
	}

	function twitoaster_wp_head() {
		global $twitoaster_options;
		printf("%s Twitoaster ( %s ) [V:%s|C:%s|ST:%s|AT:%s|M:%s] %s \n", '<!--', $twitoaster_options['profile'], $twitoaster_options['install_version'], $twitoaster_options['post_replies']['twitter_comments'], $twitoaster_options['post_replies']['show_threads'], $twitoaster_options['post_replies']['auto_threads'], $twitoaster_options['post_replies']['moderation'], '-->');
		echo '<link rel="stylesheet" href="' . get_bloginfo('wpurl') . '/wp-content' . '/plugins/' . plugin_basename(dirname(__FILE__)) . '/' . 'style.css?ver=' . $twitoaster_options['install_version'] . '" type="text/css" media="screen" />'."\n";
	}
	add_action('wp_head', 'twitoaster_wp_head');


//------------------------------------------------------------------------------
// Twitoaster API Function
//------------------------------------------------------------------------------

	function twitoaster_api_request($type, $method, $params, $timeout = 10, $format = 'php_serial')
	{
		global $twitoaster_options;

		// Init
		$process = false;
		$http_error = false;

		// Checking all parameters are UTF-8 encoded
		foreach ($params AS $key => $value) {
			if (!(seems_utf8($value))) {
				if (function_exists('mb_convert_encoding')) { $params[$key] = mb_convert_encoding($value, "UTF-8"); }
				else { $params[$key] = utf8_encode($value); }
			}
		}

		// Sending Request
		if (($method != '') AND (is_array($params)))
		{
			// Building query
			if ($type == 'POST') { $url = 'http://api.twitoaster.com/' . $method . '.' . $format; }
			else { $url = 'http://api.twitoaster.com/' . $method . '.' . $format . '?' . http_build_query($params); }

			// Sending via cURL
			if ((!(ini_get('open_basedir'))) AND (!(ini_get('safe_mode'))) AND (in_array('curl', get_loaded_extensions()))) {
		        $http = curl_init();
		        curl_setopt($http, CURLOPT_RETURNTRANSFER, true);
		        curl_setopt($http, CURLOPT_FOLLOWLOCATION, true);
		        curl_setopt($http, CURLOPT_MAXREDIRS, 5);
		        curl_setopt($http, CURLOPT_SSL_VERIFYPEER, false);
		        curl_setopt($http, CURLOPT_CONNECTTIMEOUT, 5);
		        curl_setopt($http, CURLOPT_TIMEOUT, $timeout);
		        curl_setopt($http, CURLOPT_ENCODING, "");
				curl_setopt($http, CURLOPT_USERAGENT, "Twitoaster WordPress Plugin (v"  . $twitoaster_options['install_version'] . " / using cURL)");
				curl_setopt($http, CURLOPT_REFERER, get_bloginfo('url'));
		        curl_setopt($http, CURLOPT_URL, $url);

				if ($type == 'POST') {
					curl_setopt($http, CURLOPT_POST, true);
					curl_setopt($http, CURLOPT_POSTFIELDS, http_build_query($params));
					curl_setopt($http, CURLOPT_HTTPHEADER, array("Accept-Charset: utf-8", "Expect:", "Content-type: application/x-www-form-urlencoded;charset=utf-8"));
				}
				else {
					curl_setopt($http, CURLOPT_HTTPHEADER, array("Accept-Charset: utf-8", "Expect:"));
				}

				$data = curl_exec($http);
				$curl_errno = curl_errno($http);

				if (in_array($curl_errno, array(5, 6, 7, 28))) { $http_error = 'Cannot reach the Twitoaster API (timeout). Please check the network connectivity with your Web hosting service.'; }
				else if ($curl_errno != 0) { $http_error = curl_error($http); }
				else if ($data != '') { $process = (string) $data; }
			}

			// Sending via Snoopy
			else
			{
				require_once('twitoaster-snoopy.php');
				$http = new Snoopy_Twitoaster;
				$http->maxredirs = 5;
				$http->read_timeout = $timeout;
				$http->agent = 'Twitoaster WordPress Plugin (v' . $twitoaster_options['install_version'] . ' / using Snoopy)';
				$http->referer = get_bloginfo('url');
				$http->rawheaders = array("Accept-Charset: utf-8", "Expect:");

				if ($type == 'POST') {
					$http->submit_type = 'application/x-www-form-urlencoded;charset=utf-8';
					$http->submit($url, $params);
				}
				else {
					@ $http->fetch($url);
				}

				if (@ $http->timed_out) { $http_error = 'Cannot reach the Twitoaster API (timeout). Please check the network connectivity with your Web hosting service.'; }
				else if (($data = @ $http->results) AND ($data != '')) { $process = (string) $data; }
			}
		}

		else { $process = false; }
		return twitoaster_api_process($process, $http_error);
	}

	function twitoaster_api_process($process, $http_error)
	{
		$result = array('data'=> false, 'error' => false);

		if (!(is_serialized($process))) {
			$result['error']['api_issue'] = true;
			$result['error']['user_issue'] = false;
			if ($http_error) { $result['error']['message'] = '<strong>Error: ' . $http_error . '</strong>'; }
			else if (!(empty($process))) { $result['error']['message'] = '<strong>Error: There is a problem with your Web hosting service.</strong> (' . $process . ')'; }
			else { $result['error']['message'] = '<strong>Error: Twitoaster is over capacity. Please wait a moment and try again.</strong>'; }
		}

		else {
			$process = unserialize($process);
			if (!(empty($process->error))) {
				$result['error']['api_issue'] = false;
				if (($process->error->type == 'User Not Found') OR ($process->error->type == 'User Sync Problem') OR ($process->error->type == 'Authentication Failure')) { $result['error']['user_issue'] = true; } else { $result['error']['user_issue'] = false; }
				$result['error']['message'] = '<strong>Error: ' . $process->error->message . '</strong>';
			}
			else { $result['data'] = $process; }
		}

		return $result;
	}


//------------------------------------------------------------------------------
// Twitoaster Config (Admin Page)
//------------------------------------------------------------------------------

	function twitoaster_options_page()
	{
		global $twitoaster_options;
		$newtwitoaster_options = $twitoaster_options;
		$message = ''; $error = '';

		// Twitoaster API Key Form
		if (isset($_POST['submit-api-key'])) {
			$twitoaster_api_key = stripslashes($_POST['twitoaster-api-key']);
			if ($twitoaster_api_key)
			{
				// Twitoaster API Request
				$twitoaster_api_result = twitoaster_api_request('GET', 'user/verify_api_key', array('api_key' => $twitoaster_api_key));

				// Twitoaster API Error Handling
				if ($twitoaster_api_result['error']) {
					if ($twitoaster_api_result['error']['user_issue']) { $newtwitoaster_options = twitoaster_options($newtwitoaster_options, true); }
					$error = $twitoaster_api_result['error']['message'];
				}

				// Twitoaster API Data Processing
				else {
					$newtwitoaster_options['profile'] = 'http://twitoaster.com/' . strtolower($twitoaster_api_result['data']->screen_name) . '/';
					$newtwitoaster_options['screen_name'] = $twitoaster_api_result['data']->screen_name;
					$newtwitoaster_options['user_nicename'] = strtolower($twitoaster_api_result['data']->screen_name);
					$newtwitoaster_options['user_id'] = $twitoaster_api_result['data']->id;
					$newtwitoaster_options['api_key'] = strtoupper($twitoaster_api_key);
					$message = 'Twitoaster API Key successfully Updated.';
				}
			}
		}

		// Post Replies Form
		else if (isset($_POST['submit-post-replies'])) {
			if (isset($_POST['twitoaster-post-replies-twitter-comments'])) { $newtwitoaster_options['post_replies']['twitter_comments'] = 'yes'; }
			else { $newtwitoaster_options['post_replies']['twitter_comments'] = 'no'; }

			if (isset($_POST['twitoaster-post-replies-auto-threads'])) { $newtwitoaster_options['post_replies']['auto_threads'] = 'yes'; }
			else { $newtwitoaster_options['post_replies']['auto_threads'] = 'no'; }

			if (isset($_POST['twitoaster-post-replies-auto-threads-format'])) {
				if (!(empty($_POST['twitoaster-post-replies-auto-threads-format']))) { $newtwitoaster_options['post_replies']['auto_threads_format'] = stripslashes($_POST['twitoaster-post-replies-auto-threads-format']); }
				else { $newtwitoaster_options['post_replies']['auto_threads_format'] = ''; }
			}

			if (isset($_POST['twitoaster-post-replies-moderation'])) {
				if ($_POST['twitoaster-post-replies-moderation'] == 'yes') { $newtwitoaster_options['post_replies']['moderation'] = 'yes'; }
				else { $newtwitoaster_options['post_replies']['moderation'] = 'no'; }
			}

			if (isset($_POST['twitoaster-post-replies-show-threads'])) {
				if ($_POST['twitoaster-post-replies-show-threads'] == 'yes') { $newtwitoaster_options['post_replies']['show_threads'] = 'yes'; }
				else { $newtwitoaster_options['post_replies']['show_threads'] = 'no'; }
			}

            $message = 'Twitoaster Posts Replies settings successfully Updated.';
		}

		// Post Replies Reset Form
		else if (isset($_POST['submit-post-replies-reset'])) {
			if (isset($_POST['twitoaster-post-replies-reset'])) {
				twitoaster_post_replies_reset();
				$message = 'Twitter Comments have been deleted.';
			}
		}

		// Update Options
		$currentoptions = twitoaster_options($newtwitoaster_options);
		if ($twitoaster_options != $currentoptions) {
			$twitoaster_options = $currentoptions;
			update_option('twitoaster_options', $twitoaster_options);
		}

		// Options advices
		if ($currentoptions['api_key'] == '') { $message = '<strong>Warning: You need to enter your Twitoaster API Key.</strong>'; }
		else if (!(in_array($currentoptions['post_replies']['twitter_comments'], array('yes', 'no')))) { $message = '<strong>Warning: ' . $currentoptions['post_replies']['twitter_comments'] . '</strong>'; }
		printf("%s Twitoaster Options ( %s ) %s \n", '<!--', $currentoptions['profile'], '-->');
		?>
			<?php if ($error) : ?><div id="message" class="error"><p><?php echo $error; ?></p></div><?php endif; ?>
			<?php if ($message) : ?><div id="message" class="updated fade"><p><?php echo $message; ?></p></div><?php endif; ?>

			<div class="wrap">

				<div id="icon-options-general" class="icon32"><br /></div>
				<h2>Twitoaster Account</h2>
				<p>
					Enter your <strong>Twitoaster API Key</strong> to link this blog with your Twitter account. (<a href="http://twitoaster.com/wp-login.php?force_login=true&amp;redirect_to=http://twitoaster.com/api/authentication/" target="_blank">Get your Twitoaster API Key</a>)
					<br />Feel free to <a href="http://twitoaster.com/about/contact/" target="_blank">contact us</a> if you experience any trouble with this plugin or your Twitoaster account.
				</p>
				<form name="twitoaster-form-api-key" method="post" action="#">
					<table class="form-table">
						<tr valign="top">
							<th scope="row"><label for="twitoaster-api-key">Twitoaster API Key</label></th>
							<td>
								<input name="twitoaster-api-key" type="text" id="twitoaster-api-key" value="<?php echo $currentoptions['api_key']; ?>" class="regular-text" />
							</td>
						</tr>
						<?php if ($twitoaster_options['api_key'] != '') { ?>
							<tr valign="top">
								<th scope="row"><label for="twitoaster-api-key">Linked Account</label></th>
								<td>
									<input name="twitoaster-linked-account" type="text" id="twitoaster-linked-account" value="<?php echo $currentoptions['screen_name']; ?>" class="regular-text" disabled="disabled" />
								</td>
							</tr>
							<tr valign="top">
								<th scope="row"><label>Profile URL</label></th>
								<td>
									<strong><a href="http://twitoaster.com/<?php echo $currentoptions['user_nicename']; ?>/" target="_blank">http://twitoaster.com/<?php echo $currentoptions['user_nicename']; ?></a></strong>
								</td>
							</tr>
						<?php } ?>
					</table>
					<p class="submit">
						<input type="submit" name="submit-api-key" class="button-primary" value="Save API Key" />
					</p>
				</form>

				<div id="icon-post" class="icon32"><br /></div>
				<h2>Twitoaster Posts Replies</h2>
				<h3>Twitter Comments Settings</h3>
				<p>
					Automatically retrieve Twitter replies to your Blog's posts. These "Twitter comments" are displayed like comments, on the posts pages they are related to.
					<br /><strong>Example:</strong> You Post an article on your blog; You Tweet it (or let the plugin do it for you); You automatically get all related Twitter replies as comments on your Blog post.
				</p>
				<form name="twitoaster-form-post-replies" method="post" action="#">
					<table class="form-table">
						<tr valign="top">
							<th scope="row">Enable</th>
							<td>
								<fieldset>
									<legend class="hidden">Enable</legend>
									<label>
										<input name="twitoaster-post-replies-twitter-comments" id="twitoaster-post-replies-twitter-comments" value="yes" type="checkbox"<?php checked($currentoptions['post_replies']['twitter_comments'], 'yes'); ?><?php if (!(in_array($currentoptions['post_replies']['twitter_comments'], array('yes', 'no')))) { echo ' disabled="disabled"'; }?> />&nbsp;Enable Twitter Comments.
										<span class="description">&nbsp;<?php if (!(in_array($currentoptions['post_replies']['twitter_comments'], array('yes', 'no')))) { ?><?php echo $currentoptions['post_replies']['twitter_comments']; ?><?php } ?></span>
									</label>
								</fieldset>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">Tweet Posts</th>
							<td>
								<fieldset>
									<legend class="hidden">Tweet Posts</legend>
									<label>
										<input name="twitoaster-post-replies-auto-threads" id="twitoaster-post-replies-auto-threads" value="yes" type="checkbox"<?php checked($currentoptions['post_replies']['auto_threads'], 'yes'); ?><?php if (!($currentoptions['post_replies']['twitter_comments'] == 'yes')) { echo ' disabled="disabled"'; }?> />&nbsp;Automatically Tweet your Posts.
										<span class="description">&nbsp;<?php if (!($currentoptions['post_replies']['twitter_comments'] == 'yes')) { ?>Enable Twitter Comments to use this option.<?php } else { ?><strong>Recommended</strong>. Note you can override this from Posts edit pages.<?php } ?></span>
									</label>
									<br />
									<label>
										Tweet format:&nbsp;<input name="twitoaster-post-replies-auto-threads-format" id="twitoaster-post-replies-auto-threads-format" value="<?php echo htmlspecialchars($currentoptions['post_replies']['auto_threads_format']); ?>" type="text" class="regular-text"<?php if (!($currentoptions['post_replies']['twitter_comments'] == 'yes')) { echo ' disabled="disabled"'; }?> />
										<span class="description">&nbsp;<?php if (!($currentoptions['post_replies']['twitter_comments'] == 'yes')) { ?>Enable Twitter Comments to use this option.<?php } else { ?>%url% and %postname% tags supported.<?php } ?></span>
									</label>
								</fieldset>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">Moderation</th>
							<td>
								<fieldset>
									<legend class="hidden">Moderation</legend>
									<label title="no">
										<input name="twitoaster-post-replies-moderation" value="no" type="radio"<?php checked($currentoptions['post_replies']['moderation'], 'no'); ?><?php if (!($currentoptions['post_replies']['twitter_comments'] == 'yes')) { echo ' disabled="disabled"'; }?> />&nbsp;Automatically display Twitter comments.
										<span class="description">&nbsp;<?php if (!($currentoptions['post_replies']['twitter_comments'] == 'yes')) { ?>Enable Twitter Comments to use this option.<?php } ?></span>
									</label>
									<br />
									<label title="yes">
										<input name="twitoaster-post-replies-moderation" value="yes" type="radio"<?php checked($currentoptions['post_replies']['moderation'], 'yes'); ?><?php if (!($currentoptions['post_replies']['twitter_comments'] == 'yes')) { echo ' disabled="disabled"'; }?> />&nbsp;Hold Twitter comments in the moderation queue.
										<span class="description">&nbsp;<?php if (!($currentoptions['post_replies']['twitter_comments'] == 'yes')) { ?>Enable Twitter Comments to use this option.<?php } ?></span>
									</label>
								</fieldset>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">Display</th>
							<td>
								<fieldset>
									<legend class="hidden">Display</legend>
									<label title="yes">
										<input name="twitoaster-post-replies-show-threads" value="yes" type="radio"<?php checked($currentoptions['post_replies']['show_threads'], 'yes'); ?><?php if (!($currentoptions['post_replies']['twitter_comments'] == 'yes')) { echo ' disabled="disabled"'; }?> />&nbsp;Your related Tweets and their replies.
										<span class="description">&nbsp;<?php if (!($currentoptions['post_replies']['twitter_comments'] == 'yes')) { ?>Enable Twitter Comments to use this option.<?php } ?></span>
									</label>
									<br />
									<label title="no">
										<input name="twitoaster-post-replies-show-threads" value="no" type="radio"<?php checked($currentoptions['post_replies']['show_threads'], 'no'); ?><?php if (!($currentoptions['post_replies']['twitter_comments'] == 'yes')) { echo ' disabled="disabled"'; }?> />&nbsp;Only replies to your related Tweets.
										<span class="description">&nbsp;<?php if (!($currentoptions['post_replies']['twitter_comments'] == 'yes')) { ?>Enable Twitter Comments to use this option.<?php } ?></span>
									</label>
								</fieldset>
							</td>
						</tr>
					</table>
					<p class="submit">
						<input type="submit" name="submit-post-replies" class="button-primary" value="Save Settings" />
					</p>
				</form>

				<?php if (in_array($currentoptions['post_replies']['twitter_comments'], array('yes', 'no'))) { ?>
				<h3>Twitter Comments Reset</h3>
				<p>
					<strong>Warning</strong>: this will delete all your Twitter comments! You might want to do it if you changed your Twitter account, or your Twitter Comments Display option.
					<br />If Twitter Comments is enabled, they will come back pogressively on posts where comments are enabled. Otherwise, they obviously won't come back.
				</p>
				<form name="twitoaster-form-replies-reset" method="post" action="#">
					<table class="form-table">
						<tr valign="top">
							<th scope="row">Reset</th>
							<td>
								<fieldset><legend class="hidden">Reset</legend>
									<label><input name="twitoaster-post-replies-reset" id="twitoaster-post-replies-reset" value="yes" type="checkbox" /> Yes, delete all my Twitter comments.</label>
								</fieldset>
							</td>
						</tr>
					</table>
					<p class="submit">
						<input type="submit" name="submit-post-replies-reset" class="button-primary" value="Delete all Twitter comments" />
					</p>
				</form>
				<?php } ?>

				<div id="icon-themes" class="icon32"><br /></div>
				<h2>Twitoaster Widgets</h2>
				<h3>Twitoaster Charts</h3>
				<p>
					Analytics charts showing how many replies you are generating, and what day of the week (or time of day) produces the most replies.
					<br />You can configure the color, the size, the type and the title of the chart from the <strong><a href="<?php echo get_bloginfo('wpurl'); ?>/wp-admin/widgets.php">WordPress Widgets configuration page</a></strong>.
					<br />You can also edit the CSS file located in the plugin directory, to fine tune the widget appearance to your design.
				</p>
				<h3>Twitoaster Conversations</h3>
				<p>
					Listing of your 20 most recent Twitter conversations (groups your Twitter replies with the Tweets that inspired them).
					<br />You can configure the number of conversations displayed from the <strong><a href="<?php echo get_bloginfo('wpurl'); ?>/wp-admin/widgets.php">WordPress Widgets configuration page</a></strong>.
				</p>
				<p>
					&nbsp;
				</p>
			</div>
		<?php
	}

	function twitoaster_menu() {
		if (current_user_can('manage_options')) {
			add_options_page('Twitoaster Options', 'Twitoaster', 8, 'twitoaster', 'twitoaster_options_page');
		}
	}
	add_action('admin_menu', 'twitoaster_menu');

?>
