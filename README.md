# URL checker

Finds broken links in .md files in a github repository.

Checks all links from a given github page (only from .md pages). Currently starting github URL is hardcoded as https://github.com/codedokode/pasta/blob/master/README.md

The scripts visits all .md files in a repository, finds all links within them and checks response status for those links. The list of broken links is printed to console.

The response status and response body are cache in a state.json file, so the script will not redownload hundreds of pages when restarted.

## Installation

- git clone
- composer install

## Known problems / TODO

- the scripts considers all non-html pages to be invalid
- script cannot check for parked domains
- check fragments (page.html#something)
- use HEAD requests for leaf pages where possible
- don't cache and don't even load huge files
- be able to check local HTML files
- check image/css/js references
- pick URLs from queue so that we don't have to wait
- maybe use delay based on last 2 domain parts, not whole domain
- obey robots.txt? 
