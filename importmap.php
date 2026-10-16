<?php

/**
 * Importmap panelu (D-73). Pakiety JS leżą w `assets/vendor` w repo, pobrane z registry.npmjs.org z kontrolą
 * `integrity` (sha512), bo sandbox nie ma jsDelivr; `importmap:update` w środowisku z siecią zastępuje te same pliki.
 * Licencje: MIT (Stimulus, Turbo, Chart.js, @kurkle/color). Jedna zmiana względem paczki npm: `chart.js.index.js` importuje
 * swój fragment nazwą `chart.js/chunks/helpers.dataset.js` zamiast ścieżki względnej, bo Chart.js ładuje się leniwie
 * i ścieżka względna nie trafiałaby do importmap (tak samo rozwiązuje to jsDelivr).
 *
 * @return array<string, array{path?: string, version?: string, entrypoint?: bool}>
 */
return [
    'app' => ['path' => './assets/app.js', 'entrypoint' => true],
    '@hotwired/stimulus' => ['version' => '3.2.2'],
    '@hotwired/turbo' => ['version' => '8.0.23'],
    '@symfony/stimulus-bundle' => ['path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js'],
    'chart.js' => ['version' => '4.5.1'],
    'chart.js/chunks/helpers.dataset.js' => ['version' => '4.5.1'],
    '@kurkle/color' => ['version' => '0.3.4'],
];
