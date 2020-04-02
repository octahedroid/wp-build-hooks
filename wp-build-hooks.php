<?php

/**
 * Plugin Name: Build Hooks
 * Description: This plugin allows you to trigger a build hook on Gatsby Cloud service.
 */

require plugin_dir_path(__FILE__) . 'vendor/autoload.php';

add_action('admin_menu', 'register_web_hooks_admin_page');

const BUILD_HOOK_TYPES = [
  'circle_ci'=> "CircleCI",
  'gatsby'=> 'Gatsby Cloud',
  'netlify'=> 'Netlify',
];
const BUILD_HOOK_TYPE_OPTION = '_build_hooks_type';
const BUILD_HOOK_OPTION = '_build_hooks_';
const BUILD_HOOK_CIRCLECI_REPO_OPTION = '_build_hooks_circle_ci_repository';
const BUILD_HOOK_CIRCLECI_JOB_OPTION = '_build_hooks_circle_ci_job';
const BUILD_HOOK_SETTINGS_OPTION = '_build_hooks_settings';
const BUILD_HOOK_TRIGGER_OPTION ='_build_hooks_trigger';

function build_hook_option() {
  $type = get_option(BUILD_HOOK_TYPE_OPTION);
  return get_option(BUILD_HOOK_OPTION.$type);
}

function bypass_option() {
  return in_array(
    current_user_role(),
    [
      'super_admin',
      'administrator'
    ]
  );
}

function current_user_role() {
  $current_user = wp_get_current_user();

  return $current_user->roles[0];
}

function settings_option() {
  if (bypass_option()) {
    return true;
  }

  $settings = get_option(BUILD_HOOK_SETTINGS_OPTION, []);

  return in_array(current_user_role(), $settings);
}

function trigger_option() {
  if (bypass_option()) {
    return true;
  }

  $trigger = get_option(BUILD_HOOK_TRIGGER_OPTION, []);

  return in_array(current_user_role(), $trigger);
}

function get_secret($token_name) {
  $secrets_file = file_get_contents(WP_CONTENT_DIR.'/uploads/private/secrets.json');
  $json_data = json_decode($secrets_file, true);

  return $json_data[$token_name];
}

function circle_ci_options($obfuscate = true) {
  $template = 'https://circleci.com/api/v1.1/project/{provider}/{repo}/tree/{branch}?circle-token={token}';
  $token = get_secret('CIRCLE_CI_TOKEN');
  if ($obfuscate) {
    $stars = str_repeat('*', strlen($token)-4);
    $token = substr_replace($token, $stars, 2, -2);  
  }

  $url = str_replace(
    [
      '{provider}',
      '{repo}',
      '{branch}',
      '{token}',
    ],
    [
      'gh',
      get_option(BUILD_HOOK_CIRCLECI_REPO_OPTION),
      'master',
      $token,
    ],
    $template
  );

  // @TODO implement multi-stage build
  $options = [
    'json' => [
      'build_parameters' => [
        'CIRCLE_JOB' => get_option(BUILD_HOOK_CIRCLECI_JOB_OPTION),
      ]
    ]
  ];

  return [
    'url' => $url,
    'repo' => get_option(BUILD_HOOK_CIRCLECI_REPO_OPTION),
    'job' => get_option(BUILD_HOOK_CIRCLECI_JOB_OPTION),
    'options' => $options,
  ];
}

function register_web_hooks_admin_page()
{
  if (trigger_option()) {
      add_menu_page(
          'Build Hooks',
          'Build Hooks',
          'edit_pages',
          'build-hooks',
          'build_hooks',
          'dashicons-cloud'
      );
    }

  if (settings_option()) {
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

if (isset($_POST['action'])) {
    if ($_POST['action'] === 'update_option_build_hooks') {
        setOptionsPantheon($_POST);
    }

    if ($_POST['action'] === 'trigger_build') {
        trigger_build();
    }
}

function setOptionsPantheon($data)
{
    $type = $data[BUILD_HOOK_TYPE_OPTION]?$data[BUILD_HOOK_TYPE_OPTION]:null;
    $settings = $data[BUILD_HOOK_SETTINGS_OPTION]?$data[BUILD_HOOK_SETTINGS_OPTION]:null;
    $trigger = $data[BUILD_HOOK_TRIGGER_OPTION]?$data[BUILD_HOOK_TRIGGER_OPTION]:null;
    update_option(BUILD_HOOK_TYPE_OPTION, $type);
    update_option(BUILD_HOOK_SETTINGS_OPTION, $settings);
    update_option(BUILD_HOOK_TRIGGER_OPTION, $trigger);
    if ($type === 'circle_ci') {
      $circleci_repo = $data[BUILD_HOOK_CIRCLECI_REPO_OPTION]?$data[BUILD_HOOK_CIRCLECI_REPO_OPTION]:null;
      $circleci_job = $data[BUILD_HOOK_CIRCLECI_JOB_OPTION]?$data[BUILD_HOOK_CIRCLECI_JOB_OPTION]:null;
      update_option(BUILD_HOOK_CIRCLECI_REPO_OPTION, $circleci_repo);
      update_option(BUILD_HOOK_CIRCLECI_JOB_OPTION, $circleci_job);
    } else {
      $web_hook = $data[BUILD_HOOK_OPTION.$type]?$data[BUILD_HOOK_OPTION.$type]:null;
      update_option(BUILD_HOOK_OPTION.$type, $web_hook);
    }
}

function build_hooks()
{
  $type = get_option(BUILD_HOOK_TYPE_OPTION);
  $url = build_hook_option();
  if ($type === 'circle_ci') {
    $ci_options = circle_ci_options();
    $url = $ci_options['url'];
    $circleci_repo = $ci_options['repo'];
    $circleci_job = $ci_options['job'];
  }

  ?>
    <div class="wrap">
      <h1>Build Hooks</h1>
      ​<hr />
      <?php if($type): ?>
        <h2>Web Hook</h2>
        <table class="form-table">
          <tbody>
            <tr>
              <th scope="row">Current Webhook</th>
              <td>
                <fieldset>
                  <legend class="screen-reader-text">Current Webhook</legend>
                    <input type="text" class="full-input" disabled read-only value="<?php echo $url ?>" size="96">
                  </fieldset>
              </td>
            </tr>
          </tbody>
        </table>
      <?php endif; ?>
      <?php if(trigger_option()||settings_option()) : ?>
      <hr />
      <h2>Trigger</h2>
      <form method="post" action="/wp-admin/admin.php?page=build-hooks" novalidate="novalidate">
        <div class="submit">
          <input name="action" value="trigger_build" type="hidden">
          <input name="submit" id="submit" <?php if (!$url) { echo "disabled=disabled"; } ?> class="button button-primary" value="Trigger Build" type="submit">
        </div>
      </form>
      <?php endif; ?>
    </div>
  <?php
}

function build_hooks_settings()
{
  $type = get_option(BUILD_HOOK_TYPE_OPTION);
  $url = build_hook_option();
  if ($type === 'circle_ci') {
    $ci_options = circle_ci_options(false);
    $url = $ci_options['url'];
    $circleci_repo = $ci_options['repo'];
    $circleci_job = $ci_options['job'];
  }
  $settings = get_option(BUILD_HOOK_SETTINGS_OPTION);
  $trigger = get_option(BUILD_HOOK_TRIGGER_OPTION);
  $roles = get_editable_roles();
  
  ?>
    <div class="wrap">
      <h1>Settings</h1>
      ​<hr />
      <h2>Web Hook</h2>
      <form id="hook_settings_form" method="post" action="<?php $_SERVER['PHP_SELF']?>" novalidate="novalidate">
        <table class="form-table">
          <tbody>
            <tr>
              <th scope="row">Type</th>
              <td>
                <fieldset>
                  <legend class="screen-reader-text">Type</legend>
                    <select name="<?php echo BUILD_HOOK_TYPE_OPTION ?>" id="build_hooks_type">
                      <option value="">Select type...</option>
                      <?php foreach (BUILD_HOOK_TYPES as $key => $value) { ?>
                        <option value="<?php echo $key ?>" <?php echo $type==$key?'selected':'' ?>><?php echo $value ?></option>
                      <?php } ?>
                    </select>
                </fieldset>
              </td>
            </tr>
            <?php if($type && $type !== 'circle_ci') : ?>
            <tr>
              <th scope="row">Webhook</th>
              <td>
                <fieldset>
                  <legend class="screen-reader-text">Webhook</legend>
                    <input type="text" class="full-input" name="<?php echo BUILD_HOOK_OPTION.$type ?>" value="<?php echo $url ?>" size="96">
                </fieldset>
              </td>
            </tr>
            <?php endif; ?>

            <?php if($type && $type === 'circle_ci') : ?>
            <tr>
              <th scope="row">Repository</th>
              <td>
                <fieldset>
                  <legend class="screen-reader-text">Repository</legend>
                    <input type="text" class="full-input" name="<?php echo BUILD_HOOK_CIRCLECI_REPO_OPTION ?>" value="<?php echo $circleci_repo ?>" size="96">
                </fieldset>
              </td>
            </tr>
            <tr>
              <th scope="row">Job</th>
              <td>
                <fieldset>
                  <legend class="screen-reader-text">Job</legend>
                    <input type="text" class="full-input" name="<?php echo BUILD_HOOK_CIRCLECI_JOB_OPTION ?>" value="<?php echo $circleci_job ?>" size="96">
                </fieldset>
              </td>
            </tr>
            <?php endif; ?>
          </tbody>
        </table>

      ​<hr />
      <h2>Roles with settings capabilities</h2>
        <table class="form-table">
          <tbody>
            <tr>
              <th scope="row">Roles</th>
              <td>
                <fieldset>
                  <legend class="screen-reader-text">Roles</legend>
                  <input type="hidden" name="<?php echo BUILD_HOOK_SETTINGS_OPTION ?>[]" value="administrator">
                    <?php foreach ($roles as $key => $role) {
                      ?>
                        <label for="<?php echo BUILD_HOOK_SETTINGS_OPTION.'_'.$key ?>">
                          <input type="checkbox" <?php echo $key == 'administrator'?'checked disabled':'' ?> <?php echo in_array($key, $settings) ?'checked':'' ?> name="<?php echo BUILD_HOOK_SETTINGS_OPTION ?>[]" id="<?php echo BUILD_HOOK_SETTINGS_OPTION .'_'.$key ?>" value="<?php echo $key ?>"> <?php echo $role['name'] ?>
                        </label><br />
                      <?php
                    } ?>
                </fieldset>
              </td>
            </tr>
          </tbody>
        </table>
      ​<hr />
      <h2>Roles with trigger build capabilities</h2>
        <table class="form-table">
          <tbody>
            <tr>
              <th scope="row">Roles</th>
              <td>
                <fieldset>
                  <legend class="screen-reader-text">Roles</legend>
                  <input type="hidden" name="<?php echo BUILD_HOOK_TRIGGER_OPTION ?>[]" value="administrator">
                    <?php foreach ($roles as $key => $role) {
                      ?>
                        <label for="<?php echo BUILD_HOOK_TRIGGER_OPTION.'_'.$key ?>">
                          <input type="checkbox" <?php echo $key == 'administrator'?'checked disabled':'' ?> <?php echo in_array($key, $trigger) ?'checked':'' ?> name="<?php echo BUILD_HOOK_TRIGGER_OPTION ?>[]" id="<?php echo $trigger_option.'_'.$key ?>" value="<?php echo $key ?>"> <?php echo $role['name'] ?>
                        </label><br />
                      <?php
                    } ?>
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

function trigger_build()
{
  $type = get_option(BUILD_HOOK_TYPE_OPTION);
  $url = build_hook_option();
  if ($type === 'circle_ci') {
    $ci_options = circle_ci_options(false);
    $url = $ci_options['url'];
    $options = $ci_options['options'];
  }

  $client = new \GuzzleHttp\Client([
    'headers' => ['Content-Type' => 'application/json'],
  ]);

  $response = $client->post($url, $options);
}
