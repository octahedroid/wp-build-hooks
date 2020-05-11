<?php

/**
 * Plugin Name: Build Hooks
 * Description: This plugin allows you to trigger a build hook on Gatsby Cloud service.
 */

require plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

define( 'BUILD_HOOKS_COMMANDS_PATH', 'includes/commands/' );
require_once BUILD_HOOKS_COMMANDS_PATH . 'build-hooks.php';

add_action( 'admin_menu', 'register_web_hooks_admin_page' );

const BUILD_HOOK_TYPES                   = [
	'circle_ci' => 'CircleCI',
	'gatsby'    => 'Gatsby Cloud',
	'netlify'   => 'Netlify',
];
const BUILD_HOOK_TYPE_OPTION             = '_build_hooks_type';
const BUILD_HOOK_OPTION                  = '_build_hooks_';
const BUILD_HOOK_CIRCLECI_REPO_OPTION    = '_build_hooks_circle_ci_repository';
const BUILD_HOOK_CIRCLECI_JOB_TOKEN_NAME = 'CIRCLE_CI_TOKEN';
const BUILD_HOOK_CIRCLECI_JOB_TOKEN      = '_build_hooks_circle_ci_token';
const BUILD_HOOK_CIRCLECI_WORKFLOW       = '_build_hooks_circle_ci_workflow';
const BUILD_HOOK_SETTINGS_OPTION         = '_build_hooks_settings';
const BUILD_HOOK_TRIGGER_OPTION          = '_build_hooks_trigger';
const BUILD_HOOK_SECRET_FILE_PATH        = WP_CONTENT_DIR . '/uploads/private/secrets.json';
const BUILD_HOOK_SECRET_DIRECTORY_NAME   = WP_CONTENT_DIR . '/uploads/private';

function build_hook_option() {
	$type = get_option( BUILD_HOOK_TYPE_OPTION );
	return get_option( BUILD_HOOK_OPTION . $type );
}

function bypass_option() {
	return in_array(
		current_user_role(),
		[
			'super_admin',
			'administrator',
		]
	);
}

function current_user_role() {
	$current_user = wp_get_current_user();

	return $current_user->roles[0];
}

function settings_option() {
	if ( bypass_option() ) {
		return true;
	}

	$settings = get_option( BUILD_HOOK_SETTINGS_OPTION, [] );

	return in_array( current_user_role(), $settings );
}

function trigger_option() {
	if ( bypass_option() ) {
		return true;
	}

	$trigger = get_option( BUILD_HOOK_TRIGGER_OPTION, [] );

	return in_array( current_user_role(), $trigger );
}

function get_secret_file() {
	$secret_file_path = BUILD_HOOK_SECRET_FILE_PATH;
	if ( file_exists( $secret_file_path ) ) {
		return file_get_contents( $secret_file_path );
	}
	return false;
}

function get_secret( $token_name ) {
	$secrets_file = get_secret_file();
	if ( $secrets_file ) {
		$json_data = json_decode( $secrets_file, true );
		return $json_data[ $token_name ] ? $json_data[ $token_name ] : false;
	}
	return false;
}

function create_secret_file() {
	$create_path = wp_mkdir_p( BUILD_HOOK_SECRET_DIRECTORY_NAME );
	if ( ! $create_path ) {
		throw new Exception( 'Fail to create private folder', 1 );
	}
	$file = write_secret_file( '' );
	if ( false === $file ) {
		throw new Exception( 'Fail to create secret token file', 1 );
	} else {
		return get_secret_file();
	}
}

function write_secret_file( string $content = '' ) {
	return file_put_contents( BUILD_HOOK_SECRET_FILE_PATH, $content );
}

function set_secret( $token_name, $token_value ) {
	if ( ! $token_value ) {
		return;
	}

	$secrets_file = get_secret_file();
	if ( ! $secrets_file ) {
		try {
			$secrets_file = create_secret_file();
		} catch ( \Throwable $th ) {
			$result = new WP_Error( 'broke', __( 'Token could not be saved', 'build_hooks' ) );
			echo $result->get_error_message();
		}
	}
	$json_data                = json_decode( $secrets_file, true );
	$json_data[ $token_name ] = $token_value;
	write_secret_file( json_encode( $json_data ) );
}

function get_circle_ci_repo() {
	return str_replace(
		[ 'https://', 'http://', 'github.com', 'bitbucket.com' ],
		[ '', '', 'github', 'bitbucket' ],
		get_option( BUILD_HOOK_CIRCLECI_REPO_OPTION )
	);
}

function set_circle_ci_token( $circleci_token = null ) {
	if ( ! empty( $_ENV['PANTHEON_ENVIRONMENT'] ) ) {
		if ( null === $circleci_token ) {
			clean_secret_token();
			return;
		}

		set_secret( BUILD_HOOK_CIRCLECI_JOB_TOKEN_NAME, $circleci_token );
		return;
	}

	update_option( BUILD_HOOK_CIRCLECI_JOB_TOKEN, $circleci_token );
}

function get_circle_ci_token() {
	if ( ! empty( $_ENV['PANTHEON_ENVIRONMENT'] ) ) {
		return get_secret( BUILD_HOOK_CIRCLECI_JOB_TOKEN_NAME );
	}

	return get_option( BUILD_HOOK_CIRCLECI_JOB_TOKEN );
}

function circle_ci_options( $obfuscate = true ) {
	$template = 'https://circleci.com/api/v2/project/{repo}/pipeline?circle-token={token}';
	$token    = get_circle_ci_token();

	if ( $obfuscate && $token ) {
		$stars = str_repeat( '*', strlen( $token ) - 4 );
		$token = substr_replace( $token, $stars, 2, -2 );
	}

	$url = str_replace(
		[
			'{repo}',
			'{branch}',
			'{token}',
		],
		[
			get_circle_ci_repo(),
			'master',
			$token,
		],
		$template
	);

	return [
		'url'     => $url,
		'repo'    => get_option( BUILD_HOOK_CIRCLECI_REPO_OPTION ),
		'token'   => $token,
		'options' => [],
	];
}

function circle_ci_pipeline_current() {
	$token    = get_circle_ci_token();
	$workflow = get_option( BUILD_HOOK_CIRCLECI_WORKFLOW );

	if ( ! $token || ! $workflow ) {
		return [];
	}

	$pipeline = circle_ci_pipeline( $workflow );

	return current( $pipeline['items'] );
}

function circle_ci_worklflow_link( $pipeline_number, $id ) {
	return str_replace(
		[
			'{repo}',
			'{pipeline_number}',
			'{id}',
		],
		[
			get_circle_ci_repo(),
			$pipeline_number,
			$id,
		],
		'https://app.circleci.com/pipelines/{repo}/{pipeline_number}/workflows/{id}'
	);
}

function circle_ci_pipeline( $id ) {
	$token = get_circle_ci_token();
	$url   = str_replace(
		[
			'{id}',
			'{token}',
		],
		[
			$id,
			$token,
		],
		'https://circleci.com/api/v2/pipeline/{id}/workflow?circle-token={token}'
	);

	try {
		$client = get_client();
		$result = $client->get( $url );
		$data   = json_decode( $result->getBody()->getContents(), true );

		return $data;
	} catch ( \Throwable $th ) {
		$result = new WP_Error( 'broke', __( 'Invalid POST executed. Check the entered  web-hook, token and project-name values.', 'build_hooks' ) );
		echo $result->get_error_message();
	}
}

function circle_ci_pipelines() {
	$token = get_circle_ci_token();
	$repo  = get_circle_ci_repo();

	if ( ! $token || ! $repo ) {
		return [];
	}

	$url = str_replace(
		[
			'{repo}',
			'{token}',
		],
		[
			$repo,
			$token,
		],
		'https://circleci.com/api/v2/project/{repo}/pipeline?circle-token={token}'
	);

	$client    = get_client();
	$pipelines = [];
	try {
		$response      = $client->get( $url );
		$response_body = json_decode( $response->getBody()->getContents(), true );
		if ( $response_body['items'] ) {
			$pipelines = array_chunk( $response_body['items'], 10 )[0];
		}
	} catch ( \Throwable $th ) {
		$result = new WP_Error( 'broke', __( 'Invalid POST executed. Check the entered  web-hook, token and project-name values.', 'build_hooks' ) );
		echo $result->get_error_message();
	}

	$workflows = [];
	foreach ( $pipelines as $key => $item ) {
		$pipeline_url = str_replace(
			[
				'{id}',
				'{token}',
			],
			[
				$item['id'],
				$token,
			],
			'https://circleci.com/api/v2/pipeline/{id}/workflow?circle-token={token}'
		);
		try {
			$pipeline_response = $client->get( $pipeline_url );
			$pipeline_data     = json_decode( $pipeline_response->getBody()->getContents(), true );
			if ( $pipeline_data['items'] ) {
				foreach ( $pipeline_data['items'] as $key => $pipeline_item ) {
					$created = new \DateTime( $pipeline_item['created_at'] );
					$stopped = new \DateTime( $pipeline_item['stopped_at'] );
					$now     = new \DateTime();
					$started = $now->diff( $created );
					$format  = '';
					if ( $started->d > 0 ) {
						$format = '%d days, ';
					}
					if ( $started->h > 0 ) {
						$format .= '%h hours, ';
					}
					$duration    = $stopped->diff( $created );
					$workflows[] = [
						'id'         => $pipeline_item['id'],
						'url'        => circle_ci_worklflow_link( $pipeline_item['pipeline_number'], $pipeline_item['id'] ),
						'name'       => $pipeline_item['name'],
						'status'     => $pipeline_item['status'],
						'created_at' => $created->format( 'm-d-Y h:i:s' ),
						'stopped_at' => $stopped->format( 'm-d-Y h:i:s' ),
						'started'    => $started->format( $format . '%i minutes, and %s seconds ago' ),
						'duration'   => $duration->format( '%i minute and %s seconds' ),
					];
				}
			}
		} catch ( \Throwable $th ) {
			$result = new WP_Error( 'broke', __( 'Invalid POST executed. Check the entered  web-hook, token and project-name values.', 'build_hooks' ) );
			echo $result->get_error_message();
		}
	}

	return $workflows;
}

function register_web_hooks_admin_page() {
	if ( trigger_option() ) {
		add_menu_page(
			'Build Hooks',
			'Build Hooks',
			'edit_pages',
			'build-hooks',
			'build_hooks',
			'dashicons-cloud'
		);
	}

	if ( settings_option() ) {
		add_submenu_page(
			'build-hooks',
			'Settings',
			'Settings',
			'edit_pages',
			'build-hooks-settings',
			'build_hooks_settings'
		);
	}
}

function set_options_pantheon( $data ) {
	$type     = $data[ BUILD_HOOK_TYPE_OPTION ] ? $data[ BUILD_HOOK_TYPE_OPTION ] : null;
	$settings = $data[ BUILD_HOOK_SETTINGS_OPTION ] ? $data[ BUILD_HOOK_SETTINGS_OPTION ] : null;
	$trigger  = $data[ BUILD_HOOK_TRIGGER_OPTION ] ? $data[ BUILD_HOOK_TRIGGER_OPTION ] : null;
	update_option( BUILD_HOOK_TYPE_OPTION, $type );
	update_option( BUILD_HOOK_SETTINGS_OPTION, $settings );
	update_option( BUILD_HOOK_TRIGGER_OPTION, $trigger );
	if ( 'circle_ci' === $type ) {
		$circleci_repo  = $data[ BUILD_HOOK_CIRCLECI_REPO_OPTION ] ? $data[ BUILD_HOOK_CIRCLECI_REPO_OPTION ] : null;
		$circleci_token = $data[ BUILD_HOOK_CIRCLECI_JOB_TOKEN ] ? $data[ BUILD_HOOK_CIRCLECI_JOB_TOKEN ] : null;
		update_option( BUILD_HOOK_CIRCLECI_REPO_OPTION, $circleci_repo );
		set_circle_ci_token( $circleci_token );
	} else {
		$web_hook = $data[ BUILD_HOOK_OPTION . $type ] ? $data[ BUILD_HOOK_OPTION . $type ] : null;
		update_option( BUILD_HOOK_OPTION . $type, $web_hook );
		set_circle_ci_token( null );
	}
}
function add_hook_actions() {
	if ( isset( $_POST['action'] ) ) {
		if ( 'update_option_build_hooks' === $_POST['action'] ) {
			set_options_pantheon( $_POST );
			return;
		}

		if ( 'trigger_build' === $_POST['action'] ) {
			trigger_build();
			return;
		}

		if ( 'trigger_deploy' === $_POST['action'] ) {
			$options['json'] = [
				'parameters' =>
					[
						'run-build-and-deploy-master' => false,
						'run-build-and-deploy-pr'     => false,
						'run-build-and-deploy-mu'     => false,
						'run-deploy-test-to-live'     => true,
					],
			];
			trigger_build( $options );
			return;
		}
	}
}
add_action( 'init', 'add_hook_actions', 10 );

function build_hooks() {
	$type = get_option( BUILD_HOOK_TYPE_OPTION );
	$url  = build_hook_option();
	if ( 'circle_ci' === $type ) {
		$ci_options = circle_ci_options();
		$url        = $ci_options['url'];
		$workflow   = circle_ci_pipeline_current();
		$workflows  = circle_ci_pipelines();
		$status     = [
			'running'   => 'warning',
			'success'   => 'success',
			'completed' => 'success',
			'failed'    => 'error',
			'canceled'  => 'error',
		];

		$disable = false;
		if ( ! $url ) {
			$disable = true;
		}
		if ( 'running' === $workflow && $workflow['status'] ) {
			$disable = true;
		}
	}

	?>
	<div class="wrap">
		<h1>Build Hooks</h1>
		​
		<hr />
		<?php if ( $type ) : ?>
			<h2>Web Hook</h2>
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">Current Webhook</th>
						<td>
							<fieldset>
								<legend class="screen-reader-text">Current Webhook</legend>
								<input type="text" class="full-input" disabled read-only value="<?php echo $url; ?>" size="96">
							</fieldset>
						</td>
					</tr>
				</tbody>
			</table>
		<?php endif; ?>
		<?php if ( trigger_option() || settings_option() ) : ?>
			<h2>Trigger</h2>
			<hr />
			<table>
				<tbody>
					<tr>
						<td>
							<form method="post" action="/wp-admin/admin.php?page=build-hooks" novalidate="novalidate">
								<div class="submit">
									<input name="action" value="trigger_build" type="hidden">
									<input name="submit" id="submit"
									<?php
									if ( $disable ) {
										echo 'disabled=disabled';
									}
									?>
									class="button button-primary" value="Trigger Build" type="submit">
								</div>
							</form>
						</td>

						<td> | </td>

						<td>
							<form method="post" action="/wp-admin/admin.php?page=build-hooks" novalidate="novalidate">
								<div class="submit">
									<input name="action" value="trigger_deploy" type="hidden">
									<input name="submit" id="submit"
									<?php
									if ( $disable ) {
										echo 'disabled=disabled';
									}
									?>
									class="button button-secondary" value="Deploy to Live" type="submit">
								</div>
							</form>
						</td>
					</tr>
				</tbody>
			</table>
		<?php endif; ?>
		<?php if ( $workflows ) : ?>
			<hr />
			<h2>Latest workflow executions</h2>
			<table class="wp-list-table widefat striped">
				<thead>
					<tr>
						<th>Status</th>
						<th>Name</th>
						<th>Started</th>
						<th>Duration</th>
					</tr>
					<thead>
					<tbody>
						<?php foreach ( $workflows as $key => $workflow ) { ?>
							<tr>
								<td>
									<span class="notice notice-<?php echo $status[ $workflow['status'] ]; ?>">
										<a target="_blank" href="<?php echo $workflow['url']; ?>">
											<?php echo $workflow['status']; ?>
										</a>
									</span>
								</td>
								<td>
									<a target="_blank" href="<?php echo $workflow['url']; ?>">
										<?php echo $workflow['name']; ?>
									</a>
								</td>
								<td>
									<?php echo $workflow['started']; ?>
								</td>
								<td>
									<?php echo $workflow['duration']; ?>
								</td>
							</tr>
						<?php } ?>
					</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

function build_hooks_settings() {
	$type = get_option( BUILD_HOOK_TYPE_OPTION );
	$url  = build_hook_option();
	if ( 'circle_ci' === $type ) {
		$ci_options     = circle_ci_options( false );
		$url            = $ci_options['url'];
		$circleci_repo  = $ci_options['repo'];
		$circleci_token = $ci_options['token'];
	}
	$settings = get_option( BUILD_HOOK_SETTINGS_OPTION );
	$trigger  = get_option( BUILD_HOOK_TRIGGER_OPTION );
	$roles    = get_editable_roles();
	?>
	<div class="wrap">
		<h1>Settings</h1>
		​
		<hr />
		<h2>Web Hook</h2>
		<form id="hook_settings_form" method="post" action="<?php $_SERVER['PHP_SELF']; ?>" novalidate="novalidate">
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">Type</th>
						<td>
							<fieldset>
								<legend class="screen-reader-text">Type</legend>
								<select name="<?php echo BUILD_HOOK_TYPE_OPTION; ?>" id="build_hooks_type">
									<option value="">Select type...</option>
									<?php foreach ( BUILD_HOOK_TYPES as $key => $value ) { ?>
										<option value="<?php echo $key; ?>" <?php echo $type === $key ? 'selected' : ''; ?>><?php echo $value; ?></option>
									<?php } ?>
								</select>
							</fieldset>
						</td>
					</tr>
					<?php if ( 'circle_ci' !== $type && $type ) : ?>
						<tr>
							<th scope="row">Webhook</th>
							<td>
								<fieldset>
									<legend class="screen-reader-text">Webhook</legend>
									<input type="text" class="full-input" name="<?php echo BUILD_HOOK_OPTION . $type; ?>" value="<?php echo $url; ?>" size="96">
									<p class="description" id="webhooks-description">Please provide the url to send a POST request and trigger a new build whenever you publish content. <br/>E.g.: <em>https://api.provider.com/build_hooks/XcXdfa587588ddb1b80c5XXx</em></p>
								</fieldset>
							</td>
						</tr>
					<?php endif; ?>

					<?php if ( 'circle_ci' === $type && $type ) : ?>
						<tr>
							<th scope="row">Repository</th>
							<td>
								<fieldset>
									<legend class="screen-reader-text">Repository</legend>
									<input type="text" class="full-input" name="<?php echo BUILD_HOOK_CIRCLECI_REPO_OPTION; ?>" value="<?php echo $circleci_repo; ?>" size="96">
									<p class="description" id="circle_ci-description">
										Please provide your repository information.<br/> E.g.: <em>my-provider/my-username/my-repo-name</em> or using repository url: <em>https://my-provider.com/my-username/my-repo-name</em>
									</p>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row">Token</th>
							<td>
								<fieldset>
									<legend class="screen-reader-text">Token</legend>
									<input type="text" class="full-input" name="<?php echo BUILD_HOOK_CIRCLECI_JOB_TOKEN; ?>" value="<?php echo $circleci_token; ?>" size="96">
											<p class="description" id="circle_ci-description">Please provide the api token for Circle CI, for more information please go to <a href="https://circleci.com/docs/2.0/managing-api-tokens/" >Managing API Tokens</a></p>
								</fieldset>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<hr />
			<h2>Roles with settings capabilities</h2>
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">Roles</th>
						<td>
							<fieldset>
								<legend class="screen-reader-text">Roles</legend>
								<input type="hidden" name="<?php echo BUILD_HOOK_SETTINGS_OPTION; ?>[]" value="administrator">
								<?php
								foreach ( $roles as $key => $role ) {
									?>
									<label for="<?php echo BUILD_HOOK_SETTINGS_OPTION . '_' . $key; ?>">
										<input type="checkbox" <?php echo 'administrator' === $key ? 'checked disabled' : ''; ?> <?php echo in_array( $key, $settings, true ) ? 'checked' : ''; ?> name="<?php echo BUILD_HOOK_SETTINGS_OPTION; ?>[]" id="<?php echo BUILD_HOOK_SETTINGS_OPTION . '_' . $key; ?>" value="<?php echo $key; ?>"> <?php echo $role['name']; ?>
									</label><br />
									<?php
								}
								?>
							</fieldset>
						</td>
					</tr>
				</tbody>
			</table>
			​
			<hr />
			<h2>Roles with trigger build capabilities</h2>
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">Roles</th>
						<td>
							<fieldset>
								<legend class="screen-reader-text">Roles</legend>
								<input type="hidden" name="<?php echo BUILD_HOOK_TRIGGER_OPTION; ?>[]" value="administrator">
								<?php
								foreach ( $roles as $key => $role ) {
									?>
									<label for="<?php echo BUILD_HOOK_TRIGGER_OPTION . '_' . $key; ?>">
										<input type="checkbox" <?php echo 'administrator' === $key ? 'checked disabled' : ''; ?> <?php echo in_array( $key, $trigger, true ) ? 'checked' : ''; ?> name="<?php echo BUILD_HOOK_TRIGGER_OPTION; ?>[]" id="<?php echo $trigger_option . '_' . $key; ?>" value="<?php echo $key; ?>"> <?php echo $role['name']; ?>
									</label><br />
									<?php
								}
								?>
							</fieldset>
						</td>
					</tr>
				</tbody>
			</table>
			<div class="submit">
				<input name="action" value="update_option_build_hooks" type="hidden">
				<input name="submit" id="submit" class="button button-primary" value="Save changes" type="submit">
			</div>
		</form>
		<script type="text/javascript">
			jQuery(function($) {
				$('#build_hooks_type').on('change', function() {
					$('#hook_settings_form #submit').click();
				});
			});
		</script>
	</div>
	<?php
}

function trigger_build( $build_options = [] ) {
	$type    = get_option( BUILD_HOOK_TYPE_OPTION );
	$url     = build_hook_option();
	$options = [];
	if ( 'circle_ci' === $type ) {
		$ci_options = circle_ci_options( false );
		$url        = $ci_options['url'];
		$options    = array_merge(
			$ci_options['options'],
			$build_options
		);
	}
	$client = get_client();
	try {
		$response = $client->post( $url, $options );
		$data     = json_decode( $response->getBody()->getContents(), true );
		update_option( BUILD_HOOK_CIRCLECI_WORKFLOW, $data['id'] );

	} catch ( \Throwable $th ) {
		$result = new WP_Error( 'broke', __( 'Invalid POST executed. Check the entered  web-hook, token and project-name values.', 'build_hooks' ) );
		return $result->get_error_message();
	}
}

function get_client() {
	return new \GuzzleHttp\Client(
		[
			'headers' => [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
		]
	);
}

function clean_secret_token() {
	$secrets_file = get_secret_file();
	if ( $secrets_file ) {
		$json_data = json_decode( $secrets_file, true );
		unset( $json_data[ BUILD_HOOK_CIRCLECI_JOB_TOKEN_NAME ] );
		write_secret_file( json_encode( $json_data, true ) );
	}
}
function clear_options_pantheon() {
	delete_option( BUILD_HOOK_TYPE_OPTION );
	delete_option( BUILD_HOOK_SETTINGS_OPTION );
	delete_option( BUILD_HOOK_TRIGGER_OPTION );
	delete_option( BUILD_HOOK_CIRCLECI_REPO_OPTION );
	foreach ( BUILD_HOOK_TYPES as $key => $value ) {
		delete_option( BUILD_HOOK_OPTION . $key );
	}
	set_circle_ci_token( null );
}

function on_build_hooks_deactivation() {
	clear_options_pantheon();
}
register_deactivation_hook( __FILE__, 'on_build_hooks_deactivation' );
