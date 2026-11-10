# Publication checklist

## Prepared locally

- Isolated source tree; excludes server notes, credentials, customer screenshots, earlier experimental packages and private configuration.
- MIT license, author identity, English documentation and generated logo.
- Reproducible self-contained PLG builder, offline package checks and test workflows.
- Templates/finalizer for the Community Applications profile and plugin XML.

## Requires the maintainer's account

1. Connect GitHub and resolve the account/organization owner.
2. Create the public repository `unraid-storage-quota-manager` under that owner; enable Issues for support.
3. Run `python3 tools/finalize_publication.py --owner ACCOUNT` to create canonical project/raw/update/icon URLs and wrapper XML. The MIT license choice has been delegated to the assistant by the maintainer.
4. Run tests, build, package check and `tools/validate_publication.py`; inspect the diff before pushing.
5. Push the isolated repository, confirm public HTTPS URLs actually serve the expected PLG/logo/XML and inspect CI.
6. Open https://ca.unraid.net/submit, run Validate and Scan, review the card and request manual review. The account holder must handle login, CAPTCHA and acceptance of legal terms when required.

No approval or catalog inclusion is guaranteed. The wrapper uses Beta=true and documents the known retained-loop limitation.

Official requirements: https://ca.unraid.net/submit/help and https://ca.unraid.net/submit/help/repository-xml.
