# Nginx Configuration for Grocers List WordPress Plugin

## Overview

This plugin is designed to work with both Apache and Nginx web servers. While WordPress handles most server compatibility automatically, there are some nginx-specific considerations.

## Required Nginx Configuration

### Basic WordPress Configuration

Ensure your nginx configuration includes the standard WordPress setup:

```nginx
location / {
    try_files $uri $uri/ /index.php?$args;
}

location ~ \.php$ {
    include fastcgi_params;
    fastcgi_intercept_errors on;
    fastcgi_pass php;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

### AJAX Requests

Ensure AJAX requests are properly handled:

```nginx
location /wp-admin/admin-ajax.php {
    include fastcgi_params;
    fastcgi_intercept_errors on;
    fastcgi_pass php;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;

    # Allow longer execution time for migration operations
    fastcgi_read_timeout 300;
}
```

### Static Assets

For better performance with the plugin's admin UI assets:

```nginx
location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg)$ {
    expires 1y;
    add_header Cache-Control "public, immutable";
    try_files $uri =404;
}
```

### Security Headers

Optional security improvements:

```nginx
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header X-Content-Type-Options "nosniff" always;
```

## WordPress Multisite (if applicable)

If running WordPress multisite with subdirectories:

```nginx
if (!-e $request_filename) {
    rewrite /wp-admin$ $scheme://$host$uri/ permanent;
    rewrite ^/[_0-9a-zA-Z-]+(/wp-.*) $1 last;
    rewrite ^/[_0-9a-zA-Z-]+(/.*\.php)$ $1 last;
}
```

## PHP Configuration

Ensure your PHP-FPM configuration supports:

- PHP 7.4.33 or higher
- `allow_url_fopen = On` (for API calls)
- Sufficient memory limit (at least 128M recommended)
- Maximum execution time of at least 30 seconds

## Troubleshooting

### Plugin Activation Issues

If the plugin fails to activate:

1. Check nginx error logs: `tail -f /var/log/nginx/error.log`
2. Check PHP error logs: `tail -f /var/log/php/error.log`
3. Ensure file permissions allow WordPress to write to the plugin directory
4. Verify the `wp-content/uploads` directory is writable

### AJAX Request Failures

If admin functionality doesn't work:

1. Verify the admin-ajax.php location block is configured correctly
2. Check that fastcgi_read_timeout is sufficient for long operations
3. Ensure the nonce verification is not being blocked by security plugins

### API Connection Issues

If external API calls fail:

1. Verify `allow_url_fopen` is enabled in PHP
2. Check if a firewall is blocking outbound HTTPS connections
3. Ensure DNS resolution works for `app.grocerslist.com`

## Testing the Configuration

After setup, test the following:

1. Plugin activation should complete without errors
2. The "Grocers List" menu should appear in WordPress admin
3. API key validation should work in the plugin settings
4. Link migration and counting operations should complete successfully

## Support

For nginx-specific issues, please check:

1. This documentation
2. WordPress general nginx configuration guides
3. The plugin's GitHub issues page
