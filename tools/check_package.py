"""Offline checks only: never executes the installer or any storage commands."""
import base64, hashlib, io, pathlib, re, tarfile, xml.etree.ElementTree as ET, struct, zlib

root = pathlib.Path(__file__).resolve().parents[1]
dist = root / 'dist'
plg = ET.fromstring((dist / 'quota-manager.plg').read_text(encoding='utf-8'))
assert plg.tag == 'PLUGIN' and plg.attrib['name'] == 'quota-manager'
assert plg.attrib['icon'] == 'logo.png'
files = plg.findall('FILE')
assert len(files) == 2 and files[1].attrib['Method'] == 'remove'
script = files[0].findtext('INLINE')
encoded = re.search(r"\nSQM_PAYLOAD'\n(.*?)\nSQM_PAYLOAD\n", script, re.S)
if encoded is None:
    encoded = re.search(r"<<'SQM_PAYLOAD'\n(.*?)\nSQM_PAYLOAD\n", script, re.S)
payload = base64.b64decode(encoded.group(1))
assert payload == (dist / 'quota-manager-1.5.0.tgz').read_bytes()
assert hashlib.sha256(payload).hexdigest() in script
with tarfile.open(fileobj=io.BytesIO(payload), mode='r:gz') as tar:
    for member in tar.getmembers():
        assert member.isfile() and not member.name.startswith('/') and '..' not in pathlib.PurePosixPath(member.name).parts
        source = root / 'LICENSE' if member.name == 'LICENSE' else root / ('scripts' if member.name.endswith('.sh') else 'src') / member.name
        expected = source.read_bytes()
        if source.suffix in ('.php', '.page', '.html', '.css', '.js', '.sh') or member.name.startswith('event/'):
            expected = expected.replace(b'\r\n', b'\n')
        assert tar.extractfile(member).read() == expected, member.name
    assert {'lib.php', 'cli.php', 'api.php', 'QuotaManager.page', 'ui.html', 'ui.js', 'ui.css', 'images/logo.png', 'install.sh', 'uninstall.sh', 'event/started', 'event/stopping_svcs', 'event/unmounting_disks'} <= set(tar.getnames())
    assert {'QuotaManagerMain.page','main.css','main.js'} <= set(tar.getnames())
    assert 'update.php' in tar.getnames()
    png = tar.extractfile('images/logo.png').read()
    assert png.startswith(b'\x89PNG\r\n\x1a\n'), 'Corrupted PNG signature'
    offset = 8
    while offset < len(png):
        size = struct.unpack('>I', png[offset:offset+4])[0]
        chunk = png[offset+4:offset+8+size]
        crc = struct.unpack('>I', png[offset+8+size:offset+12+size])[0]
        assert zlib.crc32(chunk) & 0xffffffff == crc, 'Corrupted PNG chunk'
        offset += size + 12
    assert offset == len(png)
for line in (dist / 'SHA256SUMS').read_text().splitlines():
    digest, name = line.split('  ')
    assert hashlib.sha256((dist / name).read_bytes()).hexdigest() == digest
assert b'mkfs.ext4' in (root / 'src/lib.php').read_bytes()
print('PASS XML, embedded payload, SHA256, archive paths, complete sources and logo')






