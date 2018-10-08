<?php

namespace Omines\DataTablesBundle\Column;


use Omines\DataTablesBundle\Filter\AbstractFilter;
use Symfony\Component\OptionsResolver\OptionsResolver;

class HtmlColumn extends TextColumn
{
    protected function configureOptions(OptionsResolver $resolver)
    {
        parent::configureOptions($resolver);

        $resolver
            ->setDefault('renderedLength', 50)
            ->setDefault('className', 'html_data')
            ->setDefault('editorFieldType', 'tinymce')
            ->setAllowedTypes('renderedLength', 'int')
        ;

        return $this;
    }

    public function getRenderedLength(): ?int
    {
        return $this->options['renderedLength'];
    }

    public function isRaw(): bool
    {
        return true;
    }

    public function containsHtml(): bool {
        return true;
    }
}