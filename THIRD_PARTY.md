# Third-party notices

Plugin code is GPL-2.0-or-later; LICENSE contains the GPL text. Runtime dependencies are pinned in composer.lock with official immutable upstream references. Production distributions must preserve their license and notice files. Their original licenses apply independently.

Runtime families: Minishlink Web Push; Guzzle, PSR HTTP interfaces and Ralouphie headers; Symfony components/contracts/polyfills; Web-Token JWT library; Brick Math; Paragonie constant-time/Sodium/random compatibility; Spomky-Labs Base64URL/PKI; PSR cache/clock/container/log.

Development-only tools include PHPUnit and Sebastian/Phar/DeepCopy dependencies, PHPStan with official WordPress stubs, PHPCS/WordPressCS, ESLint, Playwright and axe. These do not belong in the production runtime package.

WordPress, bbPress and BuddyPress downloaded for isolated tests are not bundled with this plugin. Browser fixtures, local databases, API test credentials and Composer metadata proxies must never enter a distribution. Inspect production dependency licenses and package contents with the release build.

Release dependency isolation uses [PHP-Scoper 0.18.19](https://github.com/humbug/php-scoper/releases/tag/0.18.19), MIT licensed, as a development build tool only. The pinned official PHAR SHA-256 is 170fb84bd3390defb30f99f7dc39c9a89d10c29973accc26f31c00abc5b25933. Runtime dependency namespaces are prefixed as ForumIntelligenceVendor following the [upstream configuration documentation](https://github.com/humbug/php-scoper/blob/0.18.19/docs/configuration.md). Original third-party source licenses remain in the vendor directories.

The package includes `third-party-licenses.json`, listing all 32 runtime packages, their declared licenses and original license/notice hashes. `tools/license-inventory.php` snapshots these bytes before scoping and rejects any missing or altered notice after scoping. The installed-package smoke test verifies the inventory and files again.
