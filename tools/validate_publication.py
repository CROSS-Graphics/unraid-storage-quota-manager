"""Local release/privacy checks; optional HTTP verification after the repository exists."""
import argparse
import json
from pathlib import Path
import re
import urllib.request
import xml.etree.ElementTree as ET

root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('--allow-draft', action='store_true')
parser.add_argument('--verify-urls', action='store_true')
args = parser.parse_args()
metadata = json.loads((root / 'publication.json').read_text())
assert metadata['license'] == 'MIT' and metadata['license_confirmed'], 'License choice unresolved'
assert 'CROSS Graphics' in (root / 'LICENSE').read_text()
assert 'Permission is hereby granted' in (root / 'LICENSE').read_text()
assert (root / 'docs/KNOWN-ISSUES.md').is_file()
for file in root.rglob('*'):
    if not file.is_file() or any(x in file.parts for x in ('.git', 'node_modules', '__pycache__')):
        continue
    assert not file.is_symlink(), f'Unexpected symlink: {file}'
    assert file.name not in ('SERVER-NOTES.md', 'storages.json', 'smb-extra.conf'), 'Private server file included'
    if file.suffix in ('.php', '.js', '.md', '.json', '.xml', '.py', '.sh', '.cjs', '.page'):
        text = file.read_text(encoding='utf-8')
        # The validator and its publishing instructions contain the pattern themselves.
        if file.name != 'validate_publication.py':
            assert '192.168.80.107' not in text, f'Private server address: {file}'
            assert not re.search(r'[A-Z]:[\\/]Users[\\/]', text), f'Local profile path: {file}'
profile = ET.parse(root / 'ca_profile.xml').getroot()
assert profile.findtext('Profile', '').strip(), 'Empty repository profile'
project = metadata.get('repository_url')
if not project:
    assert args.allow_draft, 'GitHub owner/repository must be resolved before submission'
    print('PASS isolated files, confirmed MIT license, privacy checks, profile; DRAFT: GitHub not resolved')
    raise SystemExit(0)
plugin = ET.parse(root / 'plugins/quota-manager.xml').getroot()
for tag in ('Name', 'PluginURL', 'Project', 'Support', 'Icon', 'Overview', 'Category'):
    assert plugin.findtext(tag, '').strip(), f'Missing {tag}'
assert plugin.findtext('Beta') == 'true'
assert plugin.findtext('Project') == project
manifest = ET.parse(root / 'dist/quota-manager.plg').getroot()
assert manifest.attrib['pluginURL'] == plugin.findtext('PluginURL'), 'Update and catalog URLs differ'
assert manifest.attrib['author'] == 'CROSS Graphics'
urls = [plugin.findtext(t) for t in ('PluginURL', 'Project', 'Support', 'Icon')]
for url in urls:
    assert url.startswith('https://') and not any(x in url for x in ('YOUR_', '${', 'example.org'))
if args.verify_urls:
    for url in urls:
        request = urllib.request.Request(url, headers={'User-Agent': 'Storage-Quota-Manager-release-validation'})
        with urllib.request.urlopen(request, timeout=30) as response:
            assert response.status == 200, url
        print('HTTP OK', url)
print('PASS publication metadata, license, privacy checks and consistent HTTPS project/update/icon links')
