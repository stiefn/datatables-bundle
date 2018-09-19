<?php

/*
 * Symfony DataTables Bundle
 * (c) Omines Internetbureau B.V. - https://omines.nl/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Omines\DataTablesBundle;

use Omines\DataTablesBundle\DependencyInjection\Instantiator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\TranslatorInterface;

class DataTableFactory
{
    /** @var Instantiator */
    protected $instantiator;

    /** @var DataTableRendererInterface */
    protected $renderer;

    /** @var array<string, DataTableTypeInterface> */
    protected $resolvedTypes = [];

    /** @var array */
    protected $config;

    protected $editor;

    protected $translator;

    /**
     * DataTableFactory constructor.
     *
     * @param array $config
     * @param DataTableRendererInterface $renderer
     */
    public function __construct(
        array $config,
        DataTableRendererInterface $renderer, #
        Instantiator $instantiator,
        Editor $editor,
        TranslatorInterface $translator
    ) {
        $this->config = $config;
        $this->renderer = $renderer;
        $this->instantiator = $instantiator;
        $this->editor = $editor;
        $this->translator = $translator;
    }

    /**
     * @param array $options
     * @return DataTable
     */
    public function create(array $options = [])
    {
        $config = $this->config;

        return (new DataTable(array_merge($config['options'] ?? [], $options), $this->instantiator))
            ->setRenderer($this->renderer)
            ->setMethod($config['method'] ?? Request::METHOD_POST)
            ->setPersistState($config['persist_state'] ?? 'fragment')
            ->setTranslationDomain($config['translation_domain'] ?? 'messages')
            ->setLanguageFromCDN($config['language_from_cdn'] ?? true)
            ->setTemplate($config['template'] ?? DataTable::DEFAULT_TEMPLATE, $config['template_parameters'] ?? [])
            ->setEditor($this->editor)
            ->setTranslator($this->translator)
        ;
    }

    /**
     * @param string|DataTableTypeInterface $type
     * @param array $typeOptions
     * @param array $options
     * @return DataTable
     */
    public function createFromType($type, array $typeOptions = [], array $options = [])
    {
        $dataTable = $this->create($options);

        if (is_string($type)) {
            $name = $type;
            if (isset($this->resolvedTypes[$name])) {
                $type = $this->resolvedTypes[$name];
            } else {
                $this->resolvedTypes[$name] = $type = $this->instantiator->getType($name);
            }
        }

        $type->configure($dataTable, $typeOptions);

        return $dataTable;
    }
}
