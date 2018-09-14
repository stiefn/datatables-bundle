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
            ->setAllowedTypes('renderedLength', 'int')
        ;

        return $this;
    }

    public function getRender()
    {
        return $this->options['renderedLength'];
    }

    public function isRaw(): bool
    {
        return true;
    }
}