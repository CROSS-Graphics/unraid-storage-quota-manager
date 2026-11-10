"""Build a self-contained PLG with an embedded, SHA256-checked source payload."""
import base64, gzip, hashlib, io, pathlib, tarfile, textwrap, json
from xml.sax.saxutils import quoteattr

ROOT = pathlib.Path(__file__).resolve().parents[1]
VERSION = '1.5.0'
META = json.loads((ROOT / 'publication.json').read_text(encoding='utf-8'))
REPO = META.get('repository_url')
PUBLIC_ATTRS = (' pluginURL=' + quoteattr(REPO.replace('https://github.com/', 'https://raw.githubusercontent.com/') + '/main/dist/quota-manager.plg') + ' support=' + quoteattr(REPO + '/issues')) if REPO else ''
out = ROOT / 'dist'
out.mkdir(exist_ok=True)
buf = io.BytesIO()
with tarfile.open(fileobj=buf, mode='w', format=tarfile.USTAR_FORMAT) as tar:
    files = [(p, p.relative_to(ROOT / 'src').as_posix()) for p in (ROOT / 'src').rglob('*') if p.is_file()]
    files += [(ROOT / 'scripts' / n, n) for n in ('install.sh', 'uninstall.sh')]
    files += [(ROOT / 'LICENSE', 'LICENSE')]
    for path, name in sorted(files, key=lambda x: x[1]):
        data = path.read_bytes()
        if path.suffix in ('.php', '.page', '.html', '.css', '.js', '.sh') or name.startswith('event/'):
            data = data.replace(b'\r\n', b'\n')
        info = tarfile.TarInfo(name)
        info.size, info.mtime, info.uid, info.gid = len(data), 0, 0, 0
        info.mode = 0o755 if name.startswith('event/') or name.endswith('.sh') else 0o644
        tar.addfile(info, io.BytesIO(data))
payload = gzip.compress(buf.getvalue(), mtime=0)
digest = hashlib.sha256(payload).hexdigest()
(out / f'quota-manager-{VERSION}.tgz').write_bytes(payload)
encoded = '\n'.join(textwrap.wrap(base64.b64encode(payload).decode(), 76))
install = f'''#!/bin/bash
set -euo pipefail
umask 077
work=$(mktemp -d /tmp/quota-manager-install.XXXXXXXX)
trap 'rm -rf -- "$work"' EXIT
base64 -d > "$work/package.tgz" <<'SQM_PAYLOAD'
{encoded}
SQM_PAYLOAD
printf '%s  %s\\n' '{digest}' "$work/package.tgz" | sha256sum -c -
mkdir "$work/source"
tar -xzf "$work/package.tgz" -C "$work/source" --no-same-owner
bash "$work/source/install.sh" "$work/source"
'''
remove = (ROOT / 'scripts' / 'uninstall.sh').read_text()
plg = f'''<?xml version="1.0"?>
<!DOCTYPE PLUGIN [<!ENTITY name "quota-manager"><!ENTITY version "{META["plugin_version"]}">]>
<PLUGIN name="&name;" author="CROSS Graphics" version="&version;" icon="logo.png" min="7.0.0" max="7.99.99" launch="Settings/QuotaManager"{PUBLIC_ATTRS}>
<CHANGES>1.5.0: Initial public beta based on the maintainer-tested 1.5.0 baseline. See repository testing notes and known limitations.</CHANGES>
<FILE Run="/bin/bash"><INLINE><![CDATA[
{install}]]></INLINE></FILE>
<FILE Run="/bin/bash" Method="remove"><INLINE><![CDATA[
{remove}]]></INLINE></FILE>
</PLUGIN>
'''
(out / 'quota-manager.plg').write_text(plg, encoding='utf-8', newline='\n')
checks = ''.join(f'{hashlib.sha256(p.read_bytes()).hexdigest()}  {p.name}\n' for p in [out / f'quota-manager-{VERSION}.tgz', out / 'quota-manager.plg'])
(out / 'SHA256SUMS').write_text(checks, encoding='ascii')
print(checks)






