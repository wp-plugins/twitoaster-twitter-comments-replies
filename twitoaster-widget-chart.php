<?php

//------------------------------------------------------------------------------
// Widget - Twitoaster Chart
//------------------------------------------------------------------------------

	function widget_twitoaster_chart($args)
	{
		global $twitoaster_options;
		extract($args);
		$display_content = true;
		$error = false;

		$options = get_option('widget_twitoaster_chart');
		$content = get_option('widget_twitoaster_chart_content');
		$currentoptions = widget_twitoaster_chart_options($options);

		// Twitoaster Plugin Configured
		if ($twitoaster_options['api_key'] != '') {
			$chart_url = 'http://chart.twitoaster.com/' . $twitoaster_options['user_nicename'] . '/' . $twitoaster_options['user_id'] . '/' . $currentoptions['size'];
			if ($currentoptions['type'] == 'days') { $chart_url .= '.png?title=no'; } else { $chart_url .= 'h.png?title=false'; }
			if ($currentoptions['color'] != 'blue') { $chart_url .= '&amp;color=' . $currentoptions['color']; }

			$chart_size['width'] = $currentoptions['size'];
			if ($currentoptions['size'] == 88) { $chart_size['height'] = 26; } else { $chart_size['height'] = $currentoptions['size']; }

			$chart_content = '<p><a href="' . $twitoaster_options['profile'] . '" title="@' . $twitoaster_options['screen_name'] . ' conversations"><img src="' . $chart_url . '" width="' . $chart_size['width'] . '" height="' . $chart_size['height'] . '" alt="' . $twitoaster_options['screen_name'] . ' replies" class="twitoaster_widget_chart" /></a></p>';
		}

		// Twitoaster Plugin Configuration Problem
		else {
			$display_content = false;
			$error = 'No Twitoaster API Key found!';
		}

		// Output
		printf("%s Twitoaster Charts ( %s ) %s \n", '<!--', $twitoaster_options['profile'], '-->');
		if ($display_content) { $out = $chart_content; }
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
// Widget - Twitoaster Chart Config (Admin Page)
//------------------------------------------------------------------------------

	function widget_twitoaster_chart_control()
	{
		global $twitoaster_options;

		// Twitoaster Plugin Configured
		if ($twitoaster_options['api_key'] != '') {
			$options = $newoptions = get_option('widget_twitoaster_chart');

			if (isset($_POST['twitoaster-chart-submit'])) {
				$newoptions['title'] = strip_tags(stripslashes($_POST['twitoaster-chart-title']));

				$type = stripslashes($_POST['twitoaster-chart-type']);
				if (in_array($type, array('days', 'hours'))) { $newoptions['type'] = $type; }
				else { $newoptions['type'] = 'days'; }

				$size = stripslashes($_POST['twitoaster-chart-size']);
				if (in_array($size, array('88', '125', '200'))) { $newoptions['size'] = $size; }
				else { $newoptions['size'] = '200'; }

				$color = stripslashes($_POST['twitoaster-chart-color']);
				if (in_array($color, array('blue', 'green', 'grey', 'red'))) { $newoptions['color'] = $color; }
				else { $newoptions['color'] = 'blue'; }
			}

			if ($options != $newoptions) {
				$options = $newoptions;
				update_option('widget_twitoaster_chart', $options);
			}

			$currentoptions = widget_twitoaster_chart_options($options);
			printf("%s Twitoaster Charts Control ( %s ) %s \n", '<!--', $twitoaster_options['profile'], '-->');
			?>
				<p>
					<label for="twitoaster-chart-title">Title:</label>
					<input class="widefat" id="twitoaster-chart-title" name="twitoaster-chart-title" type="text" value="<?php echo attribute_escape($currentoptions['title']); ?>" />
				</p>

				<p>
					<label for="twitoaster-chart-type">Chart Type:</label>
					<select name="twitoaster-chart-type" id="twitoaster-chart-type" class="widefat">
						<option value="days"<?php selected($currentoptions['type'], 'days'); ?>><?php _e('Days of week'); ?></option>
						<option value="hours"<?php selected($currentoptions['type'], 'hours'); ?>><?php _e('Hours of week'); ?></option>
					</select>
				</p>
				<p>
					<label for="twitoaster-chart-size">Chart Size:</label>
					<select name="twitoaster-chart-size" id="twitoaster-chart-size" class="widefat">
						<option value="88"<?php selected($currentoptions['size'], '88'); ?>><?php _e('Badge (88 x 26)'); ?></option>
						<option value="125"<?php selected($currentoptions['size'], '125'); ?>><?php _e('Button (125 x 125)'); ?></option>
						<option value="200"<?php selected($currentoptions['size'], '200'); ?>><?php _e('Small Square (200 x 200)'); ?></option>
					</select>
				</p>
				<p>
					<label for="twitoaster-chart-color">Chart Color:</label>
					<select name="twitoaster-chart-color" id="twitoaster-chart-color" class="widefat">
						<option value="blue"<?php selected($currentoptions['color'], 'blue'); ?>><?php _e('Blue'); ?></option>
						<option value="green"<?php selected($currentoptions['color'], 'green'); ?>><?php _e('Green'); ?></option>
						<option value="grey"<?php selected($currentoptions['color'], 'grey'); ?>><?php _e('Grey'); ?></option>
						<option value="red"<?php selected($currentoptions['color'], 'red'); ?>><?php _e('Red'); ?></option>
					</select>
				</p>
				<input type="hidden" id="twitoaster-chart-submit" name="twitoaster-chart-submit" value="1" />
			<?php
		}

		// Twitoaster Plugin Configuration Problem
		else {
			echo '<p><strong>' . 'No Twitoaster API Key found!' . '</strong></p><p>As an administrator, you can fix this problem on the Twitoaster plugin <strong><a href="' . get_bloginfo('wpurl') . '/wp-admin/options-general.php?page=twitoaster">configuration page</a></strong>.</p>';
		}
	}

	function widget_twitoaster_chart_options($options) {
		global $twitoaster_options;

		$currentoptions = array();
		$currentoptions['title'] = empty( $options['title'] ) ? '@' . $twitoaster_options['screen_name'] . ' replies' : apply_filters('widget_title', $options['title']);
		$currentoptions['type'] = empty( $options['type'] ) ? 'days' : $options['type'];
		$currentoptions['size'] = empty( $options['size'] ) ? '200' : $options['size'];
		$currentoptions['color'] = empty( $options['color'] ) ? 'blue' : $options['color'];

		return $currentoptions;
	}

	function twitoaster_chart_widget_init() {
		register_sidebar_widget('Twitter Charts', 'widget_twitoaster_chart');
		register_widget_control('Twitter Charts', 'widget_twitoaster_chart_control');
	}

	add_action("plugins_loaded", "twitoaster_chart_widget_init");

?>