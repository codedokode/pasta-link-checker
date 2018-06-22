# URL checker

[![Build Status](https://travis-ci.org/codedokode/pasta-link-checker.svg?branch=master)](https://travis-ci.org/codedokode/pasta-link-checker)

Finds broken links in .md files in a github repository.

Checks all links found on the site or in .md files from a github repository. Default starting URL is hardcoded as https://github.com/codedokode/pasta/blob/master/README.md , but it can be changed using CLI arguments.

The scripts visits all pages on the site, finds all links within them and checks response status for those links. The list of broken links is printed to console.

URL checker makes pauses between requests. It also uses filesystem cache.

## Installation

- git clone
- composer install

## Usage

```sh
php checker.php -u http://example.com/
```

Type `php checker.php --help` for help.

## Known problems / TODO

- [ ] script considers all non-html pages to be invalid (PDF, images)
- [ ] script cannot detect parked domains
- [ ] check fragments (page.html#something)
- [x] use HEAD requests for leaf pages where possible
- [ ] don't cache and don't even load huge files
- [ ] be able to check local HTML files
- [ ] check image/css/js references
- [x] pick URLs from queue so that we don't have to wait
- [x] find and report redirects
- [ ] maybe use delay based on last 2 domain parts, not whole domain
- [ ] maybe obey robots.txt? 
- [ ] links like https://mega.nz/#!12345 , https://rghost.net/12345 are not checked properly
- [ ] support some other 2xx codes like 203

