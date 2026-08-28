# Apache + PHP for fantasiaimportada.com.
#
# THE IMAGE SUPPLIES ONLY THE RUNTIME. WordPress core, wp-content and
# wp-config.php all arrive from the deployed tree, which docker-compose.prod.yml
# bind-mounts over /var/www/html -- so the image's own copy of WordPress is
# masked and never served. This tag therefore pins Apache and PHP, not
# WordPress, and changing it is a PHP upgrade.
#
# PHP 8.3, up from the 8.1-FPM the old host ran. WordPress 7.1 and WooCommerce
# 11 both support it, and the whole imported catalog has been exercised on 8.3
# in the local stack (docker-compose.yml uses the same tag), so this is a
# version the application has actually run rather than one it merely claims to
# support. If it ever needs rolling back, this line and the local compose file
# are the only two places to change.
FROM wordpress:php8.3-apache

# mod_remoteip rewrites REMOTE_ADDR from the header Caddy sets. Without it every
# request appears to come from the reverse proxy, and that address is not
# cosmetic: WooCommerce writes it to order records, and the Cloudflare Turnstile
# plugin sends it for verification.
#
# THE SECOND LINE IS NOT REDUNDANT. Enabling the module also brings in a stock
# conf-enabled/remoteip.conf that sets `RemoteIPHeader X-Forwarded-For` and
# trusts 10/8, 192.168/16, 169.254/16 and 127/8. Our conf sorts after it and so
# wins on the header, but RemoteIPInternalProxy is ADDITIVE -- those four ranges
# would survive, and anyone reading conf-enabled/remoteip.conf afterwards would
# reasonably conclude X-Forwarded-For was in effect. Disabling it means the
# trust list is stated in exactly one place.
RUN set -eux; \
	a2enmod remoteip; \
	a2disconf remoteip

# Loaded from conf-enabled, which Debian's apache2.conf includes BEFORE
# sites-enabled -- so these can redefine things the default vhost then uses.
COPY conf/ /etc/apache2/conf-enabled/
