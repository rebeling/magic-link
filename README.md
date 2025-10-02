# Magic Link

Adds HTMX-powered passwordless authentication to Drupal’s core login form. Users can request a one-time login link via email. Suitable for sites that want frictionless sign-in without passwords while preserving Drupal security practices.


## Requirements

- Drupal 11.2 (or later)
- HTMX module (drupal/htmx ^1.5)


## Features

- HTMX-powered passwordless authentication
- Configurable magic link expiry times
- Customizable email templates with token support
- CSRF protection and security features


## Installation

```bash
# Install dependencies
composer require 'drupal/htmx:^1.5'

composer config repositories.magic-link vcs https://github.com/rebeling/magic-link.git
composer require rebeling/magic-link:dev-main

# Enable module
drush en magic_link -y
```


## Configuration

* Go to Configuration → People → Magic Link (/admin/config/people/magic-link).
* Set link expiry (e.g., 15m, 1h, 24h).
* Customize email templates and token settings.
* Optionally set a default destination after login.

Email templates support tokens; ensure outbound email is configured.


## Usage

On the core login page, users click “Send me a magic link”, enter their email, and receive a one-time login URL.

The module validates the token, logs the user in, and redirects to the configured destination.


## Drush Command

Generate persistent magic links for development:

```bash
drush mli                              # Generate link for user 1 (1 hour expiry)
drush mli --expire=24h --uid=123       # Generate link for user 123 (24 hour expiry)
drush mli --expire=3d --destination=/admin # Generate link with custom destination
```


## Permissions

* Ensure the “Request magic link” route is accessible to anonymous users (default).
* Normal Drupal mail permissions/rate-limits apply if customized at site level.


## Security considerations

* Links are single-use and time-limited.
* CSRF protections are in place around the request lifecycle.
* Treat magic links like passwords: do not share or log them in plaintext.


## Testing

```bash
vendor/bin/phpunit -c web/core web/modules/contrib/magic-link/tests
```


## Coding Standards

See [Drupal Coding Standards](https://www.drupal.org/docs/develop/standards)

```bash
vendor/bin/phpcbf web/modules/contrib/magic-link
vendor/bin/phpcs -p -s web/modules/contrib/magic-link
```
