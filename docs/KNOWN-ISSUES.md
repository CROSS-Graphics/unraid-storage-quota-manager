# Known issues

## Loop device remains attached after unmount or update

During development, a filesystem image remained associated with a loop device after its mount was no longer visible in the current namespace. `AUTOCLEAR=1` and empty `fuser` output did not identify the remaining reference. The root cause was not established in this session.

The baseline deliberately refuses to mount/check an image that is already attached, rather than risking filesystem checks on an in-use image. Do not delete the image to resolve this condition, detach unrelated loop devices, force unmount, or destroy an XFS journal. Preserve the image, capture mount/loop/namespace diagnostics and consult the maintainer. A planned clean reboot may clear kernel references but interrupts server services; never force power-off when shutdown is stuck.

The maintainer has subsequently reported successful local testing. This report does not establish a code-level fix for every possible retained-loop condition. The first public release remains Beta and documents this limitation.

## Read-only checks can block mounting after an unclean shutdown

Before mounting, every image is checked without modification (`e2fsck -n`, `xfs_repair -n`, `btrfs check --readonly`). After power loss, an ext4 journal awaiting replay or a dirty XFS log can make the read-only check fail. The image then stays unmounted and unshared rather than being repaired automatically. Preserve the image, back it up if possible, and repair it manually with the appropriate tool only after reviewing the checker output.

## External storage and interrupted growth

This baseline does not manage physical USB mounting/ejection and does not support NTFS/exFAT. Unplugging backing storage can corrupt an image. Unmount managed images before ejecting a physical disk.

Growth failure may leave a larger image and a recovery record. Never truncate it to the old size: the filesystem may already have grown. Keep backups and obtain qualified recovery assistance. Automatic shrinking or recovery repair is not implemented.
