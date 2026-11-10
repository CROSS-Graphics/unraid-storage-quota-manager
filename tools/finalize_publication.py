"""Resolve real GitHub URLs. This script performs no network or Git operations."""
import argparse
import json
from pathlib import Path
import re
import xml.etree.ElementTree as ET

root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('--owner', required=True)
parser.add_argument('--repo', default='unraid-storage-quota-manager')
args = parser.parse_args()
if not re.fullmatch(r'[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?', args.owner):
    raise SystemExit('Invalid GitHub owner')
if not re.fullmatch(r'[A-Za-z0-9_.-]{1,100}', args.repo) or args.repo in ('.', '..'):
    raise SystemExit('Invalid repository name')
metadata = json.loads((root / 'publication.json').read_text())
if not metadata.get('license_confirmed'):
    raise SystemExit('Resolve the license choice before generating public metadata')
project = f'https://github.com/{args.owner}/{args.repo}'
raw = f'https://raw.githubusercontent.com/{args.owner}/{args.repo}/main'
metadata.update(repository_url=project, github_owner=args.owner, repository_name=args.repo)
(root / 'publication.json').write_text(json.dumps(metadata, indent=2) + '\n', encoding='utf-8')

def write_xml(path, element):
    path.parent.mkdir(exist_ok=True, parents=True)
    ET.indent(element, space='  ')
    ET.ElementTree(element).write(path, encoding='utf-8', xml_declaration=True)

profile = ET.Element('CommunityApplications')
for tag, value in {
    'Profile': 'CROSS Graphics maintains Storage Quota Manager, an independent Unraid plugin for fixed-capacity private SMB storage. Use the project issue tracker for support.',
    'Icon': raw + '/src/images/logo.png', 'WebPage': project,
}.items():
    ET.SubElement(profile, tag).text = value
write_xml(root / 'ca_profile.xml', profile)
plugin = ET.Element('Plugin')
for tag, value in {
    'Name': 'Storage Quota Manager', 'PluginURL': raw + '/dist/quota-manager.plg',
    'Project': project, 'Support': project + '/issues',
    'Icon': raw + '/src/images/logo.png', 'ReadMe': raw + '/README.md',
    'Overview': 'Create fixed-capacity filesystem images and private SMB shares using existing Unraid accounts. Includes per-user Read/ReadWrite access, a Main overview, mount/unmount and growth-only resize. Initial public beta based on maintainer-tested 1.5.0; review known loop lifecycle limitations before use. Back up data. No NTFS/exFAT support.',
    'Category': 'Tools:Utilities', 'Beta': 'true', 'License': 'MIT',
    'MinVer': '7.0.0', 'MaxVer': '7.99.99',
}.items():
    ET.SubElement(plugin, tag).text = value
write_xml(root / 'plugins/quota-manager.xml', plugin)
print('Resolved publication URLs:', project)
print('Next: build, validate, publish repository, then use CA Validate/Scan.')
