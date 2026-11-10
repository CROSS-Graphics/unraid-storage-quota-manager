# Validation evidence

- Baseline: maintainer-selected 1.5.0, with publication-only metadata/documentation changes and a generic server-name fallback.
- Maintainer statement: operation was checked on their own Unraid server. Exact test matrix, current logs and independent reproduction were not supplied with that statement.
- PHP transaction tests use fake Linux, filesystem and Samba commands plus temporary sparse files.
- Update-cache tests inspect local XML fixtures; DOM suites use jsdom and fake HTTP responses.
- Archive checks verify XML, SHA256, safe paths, source consistency and PNG integrity.

These checks do not prove actual Samba authorization, kernel loop lifecycle, real filesystem resizing, crash consistency, external hot-plug behavior or compatibility with every Unraid/third-party-plugin version.

Before expanding the supported-version claim, capture exact OS/kernel/Samba/util-linux/filesystem-tool versions, verify authorized and rejected SMB users, hash control files before/after growth, and exercise reboot/array stop/update with closed and busy files on disposable storage.
