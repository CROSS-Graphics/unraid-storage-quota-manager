# Changelog

## 1.5.0 — first public beta candidate

Based on the maintainer-selected 1.5.0 baseline. Public-release packaging adds author/license information, project/update links, Community Applications metadata, documentation and CI. The private example-server fallback is replaced with `NAS`; storage-management behavior is otherwise preserved.

Pre-release fixes to the baseline:

- ext4 pre-mount checks use read-only `e2fsck -n` instead of `e2fsck -p`, which could repair automatically. ext4 now follows the same no-repair policy as XFS (`xfs_repair -n`) and Btrfs (`btrfs check --readonly`).
- ext4 growth runs `resize2fs` online on the mounted, identity-verified loop device. Offline `resize2fs` could refuse to run without a prior forced `e2fsck` and leave the storage in recovery.

Features include fixed-capacity ext4/XFS/Btrfs images, explicit private SMB users and permissions, native Main overview, conservative lifecycle handling and growth-only resize. Later experimental NTFS and orphan-loop cleanup changes are excluded.
