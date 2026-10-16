<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 *
 * @return array<string, array{    // Import name as key, description of the imported file as value
 *     path: string,               // Logical, relative or absolute path to the file
 *     type?: 'js'|'css'|'json',   // Type of the file, defaults to 'js'
 *     entrypoint?: bool,          // Whether the file is an entrypoint, for 'js' only
 * }|array{
 *     version: string,            // Version of the remote package
 *     package_specifier?: string, // Remote "package-name/path" specifier, defaults to the import name
 *     type?: 'js'|'css'|'json',
 *     entrypoint?: bool,
 * }>
 */
return [
    'app' => ['path' => './assets/app.js', 'entrypoint' => true],
    '@hotwired/stimulus' => ['version' => '3.2.2'],
    '@symfony/stimulus-bundle' => ['path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js'],
    '@hotwired/turbo' => ['version' => '8.0.23'],
    '@fortawesome/fontawesome-free/css/all.css' => ['version' => '7.3.0', 'type' => 'css'],
    '@orchidjs/sifter' => ['version' => '1.1.0'],
    '@orchidjs/unicode-variants' => ['version' => '1.1.2'],
    'tom-select' => ['version' => '2.6.2'],
    'tom-select/dist/css/tom-select.default.min.css' => ['version' => '2.6.2', 'type' => 'css'],
    'tom-select/dist/css/tom-select.default.css' => ['version' => '2.6.2', 'type' => 'css'],
    'tom-select/dist/css/tom-select.bootstrap4.css' => ['version' => '2.6.2', 'type' => 'css'],
    'tom-select/dist/css/tom-select.bootstrap5.css' => ['version' => '2.6.2', 'type' => 'css'],
    'sortablejs' => ['version' => '1.15.6'],
    'emoji-picker-element' => ['version' => '1.29.1'],
    'idiomorph' => ['version' => '0.7.4'],
    // What's new → Send feedback → "Attach a screenshot of the page you were on"
    // (#165). Draws the page behind the dialog into a PNG in the browser;
    // imported only when somebody ticks that box, so nobody else loads it.
    'html-to-image' => ['version' => '1.11.11'],
    '@cantoo/pdf-lib' => ['version' => '2.9.1'],
    '@pdf-lib/standard-fonts' => ['version' => '1.0.0'],
    'pako' => ['version' => '2.2.0'],
    'tslib' => ['version' => '2.8.1'],
    '@pdf-lib/upng' => ['version' => '1.0.1'],
    'color' => ['version' => '4.2.3'],
    'node-html-better-parser' => ['version' => '1.5.9'],
    'html-entities' => ['version' => '2.6.0'],
    'color-string' => ['version' => '1.9.0'],
    'color-convert' => ['version' => '2.0.1'],
    'color-name' => ['version' => '1.1.4'],
    'simple-swizzle' => ['version' => '0.2.2'],
    'is-arrayish' => ['version' => '0.3.1'],
    '@noble/hashes/pbkdf2' => ['version' => '1.8.0'],
    '@noble/hashes/sha2' => ['version' => '1.8.0'],
    '@noble/hashes/crypto' => ['version' => '1.8.0'],
    'openpgp' => ['version' => '6.3.2'],
    '@scure/bip39' => ['version' => '1.6.0'],
    '@scure/bip39/wordlists/english' => ['version' => '1.6.0'],
    '@noble/ciphers/aes' => ['version' => '1.3.0'],
    '@noble/hashes/hkdf' => ['version' => '1.8.0'],
    '@noble/hashes/utils' => ['version' => '1.8.0'],
    '@scure/base' => ['version' => '1.2.5'],
    'postal-mime' => ['version' => '2.7.6'],
    'dompurify' => ['version' => '3.4.16'],
    'uqr' => ['version' => '0.1.2'],
    'pkijs' => ['version' => '3.4.1'],
    'asn1js' => ['version' => '3.0.10'],
    'pvtsutils' => ['version' => '1.3.6'],
    'pvutils' => ['version' => '1.1.5'],
    'bytestreamjs' => ['version' => '2.0.1'],
    '@noble/hashes/sha1' => ['version' => '1.8.0'],
];
