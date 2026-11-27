<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Support;

use HTMLPurifier;
use HTMLPurifier_Config;

final class RichText
{
    public static function clean(string $html): string
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'p,br,strong,b,em,i,u,s,blockquote,ul,ol,li,h2,h3,pre,code,a[href|title]');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Cache.DefinitionImpl', null);

        return (new HTMLPurifier($config))->purify($html);
    }
}
