<?php

namespace App\Services;

use App\Support\ResourceCatalog;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

class ResourceRenderer
{
    private MarkdownConverter $converter;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new TableExtension);

        $this->converter = new MarkdownConverter($environment);
    }

    public function markdown(array $resource): string
    {
        $path = ResourceCatalog::path($resource);
        if (! is_file($path)) {
            return '';
        }

        return (string) file_get_contents($path);
    }

    public function html(array $resource): string
    {
        return $this->converter->convert($this->markdown($resource))->getContent();
    }
}
