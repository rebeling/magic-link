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
composer require 'drupal/magic_link:1.x-dev@dev'

# Enable module
drush en magic_link
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
composer require --dev phpunit/phpunit
vendor/bin/phpunit -c web/core web/modules/contrib/magic_link/tests
```


## Coding Standards

See [Drupal Coding Standards](https://www.drupal.org/docs/develop/standards)

```bash
composer require --dev squizlabs/php_codesniffer drupal/coder
vendor/bin/phpcs --config-set installed_paths vendor/drupal/coder/coder_sniffer
vendor/bin/phpcs -i   # should list "Drupal" and "DrupalPractice"

# if SlevomatCodingStandard is not listed in phpcs -i
composer require --dev drupal/coder:^8.3 dealerdirect/phpcodesniffer-composer-installer:^1
composer install

# Lint against Drupal standards
vendor/bin/phpcs --standard=Drupal web/modules/contrib/magic_link
# Best-practice checks
vendor/bin/phpcs --standard=DrupalPractice web/modules/contrib/magic_link
# Auto-fix what can be fixed
vendor/bin/phpcbf --standard=Drupal web/modules/contrib/magic_link
```
