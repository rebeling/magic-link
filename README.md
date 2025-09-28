# Magic Link

A Drupal module that adds HTMX-powered passwordless authentication to the core login form. Users can request a one-time login link via email.


## Requirements
- Drupal 11
- HTMX module (`composer require 'drupal/htmx:^1.5'`)

## Features
- HTMX-powered passwordless authentication
- Configurable magic link expiry times
- Customizable email templates with token support
- CSRF protection and security features


## Installation

```bash
# Install dependencies
composer require 'drupal/htmx:^1.5'

# Enable module
drush en magic_link -y
```

## Drush Command

Generate persistent magic links for development:

```bash
drush mli                              # Generate link for user 1 (1 hour expiry)
drush mli --expire=24h --uid=123       # Generate link for user 123 (24 hour expiry)
drush mli --expire=3d --destination=/admin # Generate link with custom destination
```

## Testing

```bash
vendor/bin/phpunit -c web/core web/modules/custom/magic_link/tests
```
