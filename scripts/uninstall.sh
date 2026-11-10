#!/bin/bash
set -euo pipefail
plugin=/usr/local/emhttp/plugins/quota-manager
/usr/bin/php "$plugin/cli.php" uninstall
# Only known plugin code is removed. Images and persistent metadata are retained.
for file in lib.php cli.php api.php update.php QuotaManager.page QuotaManagerMain.page ui.html ui.css ui.js main.css main.js; do
  rm -f -- "$plugin/$file"
done
rm -f -- "$plugin/LICENSE"
for event in started stopping_svcs unmounting_disks; do
  rm -f -- "$plugin/event/$event"
done
rm -f -- "$plugin/images/logo.png"
rmdir -- "$plugin/images" 2>/dev/null || true
rmdir -- "$plugin/event" "$plugin" 2>/dev/null || true
