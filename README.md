# URL checker

Finds broken links in .md files in a github repository.

Checks all links from a given github page (only from .md pages). Currently starting github URL is hardcoded as https://github.com/codedokode/pasta/blob/master/README.md

The scripts visits all .md files in a repository, finds all links within them and checks response status for those links. The list of broken links is printed to console.

The response status and response body are cache in a state.json file, so the script will not redownload hundreds of pages when restarted.

## Installation

- git clone
- composer install

## Known problems

- state.json file grows quickly because of HTML files cache. The cache needs refactoring.
- script uses a lot of memory because of in-memory unlimited HTML cache
- the cacert bundle needs update mechanism. We should replace it with https://github.com/composer/ca-bundle
- the scripts considers all non-html pages to be invalid (PDF, images)
- script cannot check for parked domains
- robots.txt is not supported
- links like https://mega.nz/#!12345 , https://rghost.net/12345 are not checked properly
