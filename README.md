# PCO API OAuth Slim PHP Example

This is an example Slim PHP app for demonstrating how one might build an app to authenticate any PCO user
and then subsequently use that authentication to query the API.

You can learn more about Planning Center's API [here](https://developer.planning.center/docs).

## Setup

1. Create an app at [api.planningcenteronline.com](https://api.planningcenteronline.com/oauth/applications).

   Set the callback URL to be `http://localhost:8000/auth/complete`.

2. Install the required packages:

   ```bash
   composer install
   ```

3. Set your Application ID and Secret in the environment and run the app:

   ```bash
   export OAUTH_APP_ID=abcdef0123456789abcdef0123456789abcdef012345789abcdef0123456789a
   export OAUTH_SECRET=0123456789abcdef0123456789abcdef012345789abcdef0123456789abcdef0
   composer serve
   ```

4. Visit [localhost:8000](http://localhost:8000).

## Copyright & License

Copyright Ministry Centered Technologies. Licensed MIT.

