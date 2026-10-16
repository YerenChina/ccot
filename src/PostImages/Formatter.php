<?php
namespace BushcraftCN\CCOT\PostImages;
use s9e\TextFormatter\Configurator;
final class Formatter
{
    public function __invoke(Configurator $configurator): void
    {
        // Standard Flarum-compatible URL/IMG tags; do not introduce a proprietary media syntax.
        foreach(['IMG','URL']as$code)if(!isset($configurator->BBCodes[$code]))$configurator->BBCodes->addFromRepository($code);
    }
}
