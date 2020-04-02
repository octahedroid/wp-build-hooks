# Build Hooks

## Description

This plugin allows you to trigger a build hook on CircleCI and Gatsby Cloud Services.

## CircleCI

### Requirements

### Terminus Secrets Plugin

You should set a secret key named `CIRCLE_CI_TOKEN` containing your CircleCI token value in the live environemt of your wordpress site.

https://github.com/pantheon-systems/terminus-secrets-plugin

### Configure plugin

To configure the build hook on CircleCI we need add some required values.

On the settings page of this plugin, please select `CircleCI` for the Web Hook type.

Followed by the repository in format `<organization or user>/<project>` and the CircleCi Job to execute (e.g `pfe/gatsby_build_and_deploy`).

Save the settings and now we are ready to trigger a build from our wordpress site 

In order to verify if the hook is setting up correctly you can check on the build hook page the Current webhook an url similar to this:

```
https://circleci.com/api/v1.1/project/gh/<organization or user>/<project>/tree/master?circle-token=72************************************fc
```


