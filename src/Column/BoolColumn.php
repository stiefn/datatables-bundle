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

use Omines\DataTablesBundle\DataTable;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * BoolColumn.
 *
 * @author Niels Keurentjes <niels.keurentjes@omines.com>
 */
class BoolColumn extends AbstractColumn
{

    public function initialize(string $name, int $index, array $options = [], DataTable $dataTable)
    {
        parent::initialize($name, $index, $options, $dataTable);

        if(!isset($options['map'])) {
            $this->options['map'] = [
                0 => $this->options['falseValue'],
                1 => $this->options['trueValue']
            ];
        }
        $this->options['normalizedMap'] = [
            0 => $this->options['falseValue'],
            1 => $this->options['trueValue']
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function normalize($value)
    {
        return $value ? 1 : 0;
    }

    /**
     * {@inheritdoc}
     */
    protected function configureOptions(OptionsResolver $resolver)
    {
        parent::configureOptions($resolver);

        $resolver
            ->setDefault(
                'rightExpr',
                function ($value) {
                    return trim(strtolower($value)) == $this->getTrueValue();
                }
        );

        $resolver
            ->setDefault('trueValue', 'true')
            ->setDefault('falseValue', 'false')
            ->setDefault('nullValue', '')
            ->setDefault('map', [])
            ->setDefault('normalizedMap', null)
            ->setAllowedTypes('trueValue', 'string')
            ->setAllowedTypes('falseValue', 'string')
            ->setAllowedTypes('nullValue', 'string')
            ->setAllowedTypes('map', 'array')
            ->setAllowedTypes('normalizedMap', ['null', 'array'])
        ;

        return $this;
    }

    /**
     * @return string
     */
    public function getTrueValue(): string
    {
        return $this->options['trueValue'];
    }

    /**
     * @return string
     */
    public function getFalseValue(): string
    {
        return $this->options['falseValue'];
    }

    /**
     * @return string
     */
    public function getNullValue(): string
    {
        return $this->options['nullValue'];
    }

    /**
     * @param string $value
     * @return bool
     */
    public function isValidForSearch($value)
    {
        $value = trim(strtolower($value));
        return ($value == $this->getTrueValue()) || ($value == $this->getFalseValue());
    }

    public function getMap(): ?array {
        return $this->options['map'];
    }

    public function getNormalizedMap(): ?array {
        if($this->options['normalizedMap'] === null) {
            return $this->getMap();
        }
        return $this->options['normalizedMap'];
    }
}
