<?php
if ( ! defined( 'WP_CLI' ) ) {
	return;
}

/**
 * Command to trigger build hooks
 */
class BuildHooksCommand extends WP_CLI_Command {
	/**
	 * Build and deploy for managed updates
	 *
	 * ## OPTIONS
	 *
	 * <wordpress-url>
	 * : Enter the WordPress url to use es source of truth for the gatsby site
	 *
	 * <destination>
	 * : Enter the destination for the  gatsby multuidev environment
	 *
	 * ## EXAMPLES
	 *
	 *     wp build-hooks mu mu-my-awesome-site.pantheonsite.io mu-1234567
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function mu( $args, $assoc_args ) {
		list( $wordpress_url, $destination_env  ) = $args;
		$options['json']                          = [
			'parameters' =>
				[
					'wordpress-url'               => $wordpress_url,
					'destination-env'             => $destination_env,
					'run-build-and-deploy-master' => false,
					'run-build-and-deploy-pr'     => false,
					'run-build-and-deploy-mu'     => true,
					'run-deploy-test-to-live'     => false,
				],
		];
		trigger_build( $options );

		// Print a success message
		WP_CLI::success( 'Starting build from ' . $wordpress_url . ', to destination ' . $destination_env );
	}
}

WP_CLI::add_command( 'build-hooks', 'BuildHooksCommand' );
