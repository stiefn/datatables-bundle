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
 * TextColumn.
 *
 * @author Niels Keurentjes <niels.keurentjes@omines.com>
 */
class TextColumn extends AbstractColumn
{
    /**
     * {@inheritdoc}
     */
    public function normalize($value): string
    {
        $value = (string) $value;

        return $this->isRaw() ? $value : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE);
    }

    /**
     * {@inheritdoc}
     */
    protected function configureOptions(OptionsResolver $resolver)
    {
        parent::configureOptions($resolver);

        $resolver
            ->setDefault('operator', 'LIKE')
            ->setDefault(
                'rightExpr',
                function ($value) {
                    return '%' . $value . '%';
                }
            )
            ->setDefault('raw', false)
            ->setDefault('lines', 1)
            ->setDefault('maxLength', null)
            ->setAllowedTypes('raw', 'bool')
            ->setAllowedTypes('lines', 'int')
            ->setAllowedTypes('maxLength', ['null', 'int'])
        ;

        return $this;
    }

    /**
     * @return bool
     */
    public function isRaw(): bool
    {
        return $this->options['raw'];
    }
}
