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
 * DateTimeColumn.
 *
 * @author Niels Keurentjes <niels.keurentjes@omines.com>
 */
class DateTimeColumn extends AbstractColumn
{
    /**
     * {@inheritdoc}
     */
    public function normalize($value)
    {
        if (null === $value) {
            return $this->options['nullValue'];
        } elseif (!$value instanceof \DateTimeInterface) {
            if(is_integer($value)) {
                $timestamp = $value;
                $value = new \DateTime();
                $value->setTimestamp($timestamp);
            } else {
                $value = new \DateTime((string) $value);
            }
        }

        return $value->format($this->options['format']);
    }

    /**
     * {@inheritdoc}
     */
    protected function configureOptions(OptionsResolver $resolver)
    {
        parent::configureOptions($resolver);

        $resolver
            ->setDefaults([
                'format' => 'Y-m-d',
                'nullValue' => '',
            ])
            ->setAllowedTypes('format', 'string')
            ->setAllowedTypes('nullValue', 'string')
        ;

        return $this;
    }

    public function isDate(): bool {
        return true;
    }
}
