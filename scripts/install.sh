#!/bin/bash
set -euo pipefail
plugin=/usr/local/emhttp/plugins/quota-manager
stage=${1:?staging directory required}
test "$(id -u)" = 0
test -f /etc/unraid-version
if test -f "$plugin/cli.php"; then
  /usr/bin/php "$stage/cli.php" upgrade-stop
fi
# Verify platform, tools and effective Samba include before registering UI/events.
/usr/bin/php "$stage/cli.php" install
install -d -m 0755 "$plugin" "$plugin/event"
install -m 0644 "$stage/LICENSE" "$plugin/LICENSE"
install -d -m 0755 "$plugin/images"
install -m 0644 "$stage/images/logo.png" "$plugin/images/logo.png"
for file in lib.php cli.php api.php update.php QuotaManager.page QuotaManagerMain.page ui.html ui.css ui.js main.css main.js; do
  install -m 0644 "$stage/$file" "$plugin/$file"
done
for event in started stopping_svcs unmounting_disks; do
  install -m 0755 "$stage/event/$event" "$plugin/event/$event"
done
/usr/bin/php "$plugin/cli.php" upgrade-resume
echo 'Storage Quota Manager installed. Open Settings > Storage Quota Manager.'
# Updates restore only images that were mounted before this installation.
