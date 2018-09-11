<?php

/*
 * Symfony DataTables Bundle
 * (c) Omines Internetbureau B.V. - https://omines.nl/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Omines\DataTablesBundle\Column;

use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * MapColumn.
 *
 * @author Niels Keurentjes <niels.keurentjes@omines.com>
 */
class MapColumn extends TextColumn
{

    public function initialize(string $name, int $index, array $options = [], DataTable $dataTable)
    {
        parent::initialize($name, $index, $options, $dataTable);

        if(!isset($options['render'])) {
            $this->options['render'] = $this->getMapping();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function normalize($value): string
    {
        return $value;
    }

    /**
     * {@inheritdoc}
     */
    protected function configureOptions(OptionsResolver $resolver)
    {
        parent::configureOptions($resolver);

        $resolver
            ->setDefaults([
                'default' => null,
                'map' => null,
            ])
            ->setAllowedTypes('default', ['null', 'string'])
            ->setAllowedTypes('map', 'array')
            ->setRequired('map')
        ;

        return $this;
    }

    public function getMapping() {
        return $this->options['map'];
    }
}
