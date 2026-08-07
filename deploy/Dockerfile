# Production image: official Dolibarr image + this repo's patched source tree.
# The base image bakes stock Dolibarr 23.0.3 into /var/www/html at build time
# (there is no runtime source copy), so this overlay fully replaces core files
# with our patched ones (e.g. product/stock/combinations.php) and ships
# htdocs/custom/mystore inside the image.
FROM dolibarr/dolibarr:23.0.3-php8.2

COPY --chown=www-data:www-data htdocs/ /var/www/html/

# conf.php must not exist in the image — the entrypoint generates it from
# DOLI_* env vars only when absent. Then restore upstream's owner-read-only
# hardening (the entrypoint runs as root, so it can still write conf.php).
RUN rm -f /var/www/html/conf/conf.php && \
    chmod -R u-w /var/www/html
