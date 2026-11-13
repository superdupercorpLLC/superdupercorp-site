#!/bin/bash
# Run by cPanel/Flashcloud "Deploy" (see .cpanel.yml), from inside the cloned repository.
# Copies the site into ~/sdc-site. Adds and updates files only, never deletes anything,
# never touches live content (data/*.json, uploads/), and keeps the PHP-version lines that
# cPanel's MultiPHP Manager writes into .htaccess.
set -e
DEST="$HOME/sdc-site"
mkdir -p "$DEST/uploads" "$DEST/data"

# 1. Remember cPanel's PHP handler block (and any CloudLinux/LiteSpeed PHP block) before replacing .htaccess.
KEEP=""
if [ -f "$DEST/.htaccess" ]; then
  KEEP="$(sed -n -e '/# php -- BEGIN cPanel-generated handler/,/# php -- END cPanel-generated handler/p' \
                 -e '/# BEGIN cPanel-generated php ini directives/,/# END cPanel-generated php ini directives/p' \
                 -e '/<IfModule Litespeed>/,/<\/IfModule>/p' "$DEST/.htaccess")"
fi

# 2. Copy the code.
tar --exclude=./.git --exclude=./tools --exclude=./.cpanel.yml --exclude=./deploy.sh \
    --exclude=./dev-router.php --exclude='./data/*.json' --exclude='./data/*.txt' --exclude='./uploads/*' \
    -cf - . | tar -xf - -C "$DEST"

# 3. Put cPanel's PHP lines back on top of the new .htaccess.
if [ -n "$KEEP" ]; then
  { printf '%s\n\n' "$KEEP"; cat "$DEST/.htaccess"; } > "$DEST/.htaccess.new" && mv "$DEST/.htaccess.new" "$DEST/.htaccess"
fi
echo "Deployed to $DEST"
