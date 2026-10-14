<?php
declare(strict_types=1);
use PhpCsFixer\Config;
use PhpCsFixer\Finder;
return (new Config())->setCacheFile(__DIR__.'/var/.php-cs-fixer.cache')->setRiskyAllowed(false)->setRules(['@PER-CS2.0' => true])->setFinder(Finder::create()->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/scripts', __DIR__.'/tools', __DIR__.'/migrations', __DIR__.'/config'])->name('*.php'));
