<?php

namespace C33s\ConstructionKitBundle\BuildingBlock;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Yaml\Yaml;
use C33s\ConstructionKitBundle\Manipulator\KernelManipulator;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use C33s\SymfonyConfigManipulatorBundle\Manipulator\ConfigManipulator;

class BuildingBlockHandler
{
    /**
     *
     * @var KernelInterface
     */
    protected $kernel;

    /**
     *
     * @var LoggerInterface
     */
    protected $logger;

    /**
     *
     * @var ConfigManipulator
     */
    protected $configManipulator;

    /**
     *
     * @var array
     */
    protected $composerBuildingBlockClasses;

    protected $existingMapping;
    protected $newMapping;

    protected $buildingBlocks = array();
    protected $buildingBlockSources = array();

    protected $blocksToEnable = array();

    protected $environments;

    /**
     *
     * @param string $rootDir   Kernel root dir
     */
    public function __construct(KernelInterface $kernel, LoggerInterface $logger, ConfigManipulator $configManipulator, $composerBuildingBlocks, $mapping, array $environments)
    {
        $this->kernel = $kernel;
        $this->logger = $logger;
        $this->configManipulator = $configManipulator;
        $this->composerBuildingBlockClasses = $composerBuildingBlocks;
        $this->existingMapping = $mapping;
        $this->environments = $environments;
    }

    /**
     * Set a building block definition.
     *
     * @param BuildingBlockInterface $block
     * @param string $setBy                 Optional information where this block comes from (for debugging)
     */
    public function addBuildingBlock(BuildingBlockInterface $block, $setBy = '')
    {
        $block->setKernel($this->kernel);

        $this->buildingBlocks[get_class($block)] = $block;
        $this->buildingBlockSources[get_class($block)] = $setBy;
    }

    /**
     *
     */
    public function updateBuildingBlocks()
    {
        $this->loadComposerBuildingBlocks();
        $this->toggleBlocks();
        $this->saveBlocksMap();
    }

    protected function getMapping()
    {
        if (null === $this->newMapping)
        {
            $this->newMapping = $this->detectChanges();
        }

        return $this->newMapping;
    }

    /**
     * Get BuildingBlocks defined by composer
     *
     * @return BuildingBlockInterface[]
     */
    protected function loadComposerBuildingBlocks()
    {
        foreach ($this->composerBuildingBlockClasses as $package => $classes)
        {
            foreach ($classes as $class)
            {
                if (0 !== strpos($class, '\\'))
                {
                    // make sure the class always starts with a backslash to reduce danger of having duplicate classes
                    $class = '\\'.$class;
                }

                if (!class_exists($class))
                {
                    throw new \InvalidArgumentException("The building block class '$class' defined by composer package '$package' does not exist or cannot be accessed.");
                }
                if (!array_key_exists('C33s\\ConstructionKitBundle\\BuildingBlock\\BuildingBlockInterface', class_implements($class)))
                {
                    throw new \InvalidArgumentException("The building block class '$class' defined by composer package '$package' does not implement BuildingBlockInterface.");
                }

                $this->addBuildingBlock(new $class(), $package);
            }
        }
    }

    /**
     * At this point all available blocks are collected inside $this->buildingBlocks. The existing class map is placed in $this->existingMapping.
     * We have to detect both new and removed blocks and act accordingly.
     *
     * @return array    New blocks map
     */
    protected function detectChanges()
    {
        $newMap = array('building_blocks' => array());

        if (!array_key_exists('building_blocks', $this->existingMapping))
        {
            $this->existingMapping['building_blocks'] = array();
        }
        if (!array_key_exists('assets', $this->existingMapping))
        {
            $this->existingMapping['assets'] = array();
        }

        // detect new blocks
        foreach ($this->buildingBlocks as $class => $block)
        {
            if (array_key_exists($class, $this->existingMapping['building_blocks']))
            {
                // just copy over the information
                $newMap['building_blocks'][$class] = $this->existingMapping['building_blocks'][$class];
            }
            else
            {
                // This block does not appear in the existing map. Whether to enable it or not can be decided based on its autoInstall result.
                $newMap['building_blocks'][$class] = array(
                    'enabled' => (boolean) $block->isAutoInstall(),
                    'init' => true,
                    'use_config' => true,
                    'use_assets' => true,
                );
            }
        }

        ksort($newMap['building_blocks']);
        $newMap['assets'] = $this->existingMapping['assets'];

        foreach ($newMap['building_blocks'] as $class => $settings)
        {
            $useAssets = $settings['enabled'] && $settings['use_assets'];

            $block = $this->getBlock($class);
            $this->getBlockInfo($block);
            $assets = $block->getAssets();

            foreach ($assets as $group => $grouped)
            {
                if (!array_key_exists($group, $newMap['assets']))
                {
                    $newMap['assets'][$group] = array(
                        'enabled' => array(),
                        'disabled' => array(),
                        'filters' => array(),
                    );
                }

                foreach ($grouped as $asset)
                {
                    if ($useAssets && !in_array($asset, $newMap['assets'][$group]['enabled']) && !in_array($asset, $newMap['assets'][$group]['disabled']))
                    {
                        // append assets that did not appear previously
                        $newMap['assets'][$group]['enabled'][] = $asset;
                    }
                    elseif (!$useAssets && in_array($asset, $newMap['assets'][$group]['enabled']))
                    {
                        // disable previously defined assets if enabled
                        $key = array_search($asset, $newMap['assets'][$group]['enabled']);
                        unset($newMap['assets'][$group]['enabled'][$key]);
                        $newMap['assets'][$group]['enabled'] = array_values($newMap['assets'][$group]['enabled']);
                        $newMap['assets'][$group]['disabled'][] = $asset;
                    }
                }
            }
        }

        // TODO: detect removed blocks. This is actually a rare edge case since no sane person would remove a composer package without disabling its classes first. *cough*

        return $newMap;
    }

    protected function toggleBlocks()
    {
        $newMap = $this->getMapping();

        $bundlesToEnable = array();
        foreach ($newMap['building_blocks'] as $class => $settings)
        {
            $block = $this->getBlock($class);
            $block->setKernel($this->kernel);

            if ($settings['enabled'])
            {
                $bundlesToEnable = array_merge($bundlesToEnable, $this->enableBlock($block, $settings['use_config'], $settings['init']));
            }
            else
            {
                $this->disableBlock($block);
            }
        }

        $bundlesToEnable = array_unique($bundlesToEnable);
        $this->enableBundles($bundlesToEnable);
    }

    /**
     *
     * @throws \InvalidArgumentException
     *
     * @param string $class
     *
     * @return BuildingBlockInterface
     */
    protected function getBlock($class)
    {
        if (!isset($this->buildingBlocks[$class]))
        {
            throw new \InvalidArgumentException("Block class $class is not registered");
        }

        return $this->buildingBlocks[$class];
    }

    /**
     * Disable a previously enabled building block.
     * TODO: ask user whether to remove class and config
     *
     * @param BuildingBlockInterface $block
     */
    protected function disableBlock(BuildingBlockInterface $block)
    {

    }

    /**
     * Enable a building block.
     *
     * @param BuildingBlockInterface $block
     * @param boolean $useConfig
     * @param boolean $init
     *
     * @return array    List of bundles to enable for this block
     */
    protected function enableBlock(BuildingBlockInterface $block, $useConfig, $init)
    {
        $info = $this->getBlockInfo($block);

        if ($init)
        {
            $this->logger->info("Initializing ".get_class($block));
            if ($useConfig)
            {
                $comment = 'Added by '.get_class($block).' init()';
                foreach ($block->getInitialParameters() as $name => $defaultValue)
                {
                    $this->configManipulator->addParameter($name, $defaultValue, $comment);
                    $comment = null;
                }
            }

            $block->init();
            $this->markAsInitialized($block);
        }

        if ($useConfig)
        {
            $comment = 'Added by '.get_class($block);
            foreach ($block->getAddParameters() as $name => $defaultValue)
            {
                if ($init || !$this->kernel->getContainer()->hasParameter($name))
                {
                    $this->configManipulator->addParameter($name, $defaultValue, $comment);
                    $comment = null;
                }
            }

            $usedModules = array();
            foreach ($info['config_templates'] as $env => $templates)
            {
                foreach ($templates as $relative => $template)
                {
                    $module = basename($template, '.yml');
                    if ($this->configManipulator->checkCanCreateModuleConfig($module, $env, false))
                    {
                        $content = file_get_contents($template);
                        $this->configManipulator->addModuleConfig($module, $content, $env);
                    }

                    $usedModules[$env][$module] = true;
                }
            }

            foreach ($info['default_configs'] as $env => $defaults)
            {
                foreach ($defaults as $relative => $default)
                {
                    // this is the file that will hold all defaults imports per environment
                    $defaultsImporterFile = $this->configManipulator->getModuleFile($this->getDefaultsImporterModuleName(), $env);

                    // first add the imported defaults file to the defaults importer (e.g. config/_building_block_defaults.yml)
                    $this->configManipulator->getYamlManipulator()->addImportFilenameToImporterFile($defaultsImporterFile, $relative);

                    // now as the defaults importer exists we may add it to the main config file
                    $this->configManipulator->enableModuleConfig($this->getDefaultsImporterModuleName(), $env);

                    $module = basename($default, '.yml');
                    if (!isset($usedModules[$env][$module]) && $this->configManipulator->checkCanCreateModuleConfig($module, $env))
                    {
                        // any modules that only use a defaults file will be provided with a commented version of the given file.
                        $content = file_get_contents($default);
                        $content = "#".preg_replace("/\n/", "\n#", $content);
                        $content = "# This file was auto-generated based on ".$relative."\n# Feel free to change anything you have to.\n\n".$content;
                        $this->configManipulator->addModuleConfig($module, $content, $env, true);
                    }
                }
            }
        }

        return $info['bundle_classes'];
    }

    protected function getDefaultsImporterModuleName()
    {
        return '_building_block_defaults';
    }

    protected function markAsInitialized(BuildingBlockInterface $block)
    {
        $newMap = $this->getMapping();
        $newMap['building_blocks'][get_class($block)]['init'] = false;

        $this->newMapping = $newMap;
    }

    /**
     * Get all block information in a single array.
     *
     * @param BuildingBlockInterface $block
     * @throws \InvalidArgumentException
     *
     * @return array
     */
    public function getBlockInfo(BuildingBlockInterface $block)
    {
        $configTemplates = array();
        $defaultConfigs = array();
        $assets = array();
        $bundleClasses = $block->getBundleClasses();

        // check all the bundle classes first before adding anything anywhere
        foreach ($bundleClasses as $bundleClass)
        {
            if (!class_exists($bundleClass))
            {
                throw new \InvalidArgumentException("Bundle class $bundleClass cannot be resolved");
            }
        }
        foreach ($this->environments as $env)
        {
            $configTemplates[$env] = $block->getConfigTemplates($env);
            $defaultConfigs[$env] = $block->getDefaultConfigs($env);
        }

        $assets = $block->getAssets();

        return array(
            'class' => get_class($block),
            'bundle_classes' => $bundleClasses,
            'config_templates' => $configTemplates,
            'default_configs' => $defaultConfigs,
            'assets' => $assets,
        );
    }

    /**
     * Add the given bundle class to AppKernel.php, no matter if it is already in there or not. The KernelManipulator will take care of this.
     *
     * @param string $bundleClass
     */
    protected function enableBundles($bundleClasses)
    {
        try
        {
            $manipulator = new KernelManipulator($this->kernel);
            $manipulator->addBundles($bundleClasses);

            $this->logger->info("Adding ".implode(', ', $bundleClasses)." to AppKernel");
        }
        catch (\RuntimeException $e)
        {
        }
    }

    /**
     * Check if the given class name was enabled in the previous configuration.
     *
     * @param string $class
     *
     * @return boolean
     */
    protected function wasEnabled($class)
    {
        return isset($this->existingMapping['building_blocks'][$class]['enabled']) && $this->existingMapping['building_blocks'][$class]['enabled'];
    }

    /**
     * Save building block map to specific yaml config file.
     */
    protected function saveBlocksMap()
    {
        $data = array(
            'c33s_construction_kit' => array(
                'mapping' => $this->getMapping(),
            ),
        );

        $content = <<<EOF
# This file is auto-updated each time construction-kit:update-blocks is called.
# This may happen automatically during various composer events (install, update)
#
# Follow these rules for your maximum building experience:
#
# [*] Only edit existing block classes in this file. If you need to add another custom building block class use the
#     composer extra 'c33s-building-blocks' or register your block as a tagged service (tag 'c33s_building_block').
#     Make sure your block implements C33s\ConstructionKitBundle\BuildingBlock\BuildingBlockInterface
#
# [*] You can enable or disable a full building block by simply setting the "enabled" flag to true or false, e.g.:
#     C33s\ConstructionKitBundle\BuildingBlock\ConstructionKitBuildingBlock:
#         enabled: true
#
#     If you enable a block for the first time, make sure the "init" flag is also set
#     C33s\ConstructionKitBundle\BuildingBlock\ConstructionKitBuildingBlock:
#         enabled: true
#         init: true
#
# [*] "use_config" and "use_assets" flags will only be used if block is enabled. They do not affect disabled blocks.
#
# [*] Asset lists will automatically be filled by all assets of asset-enabled blocks. To exclude specific assets, move them to their
#     respective "disabled" sections. You may also reorder assets - the order will be preserved.
#
# [*] Assets are made available through assetic using the @asset_group notation.
#
# [*] Custom YAML comments in this file will be lost!
#

EOF;

        $content .= Yaml::dump($data, 6);

        // force remove empty asset arrays to ease copy&paste of YAML lines
        $content = str_replace('                disabled: {  }', '                disabled:', $content);
        $content = str_replace('                enabled: {  }', '                enabled:', $content);
        $content = str_replace('                filters: {  }', '                filters:', $content);

        $this->configManipulator->addModuleConfig('c33s_construction_kit.map', $content, '', true);
    }

    public function debug(OutputInterface $output, array $blockClasses, $showDetails = false)
    {
        $output->writeln("Updating blocks information ...");
        $this->updateBuildingBlocks();

        $blockClasses = $this->filterBlockClasses($blockClasses);

        $this->doDebug($output, $blockClasses, $showDetails);
    }

    /**
     * Filter given class names that may be incomplete to auto-fill full class names.
     *
     * @param array $newMap
     * @param array $blockClasses
     *
     * @return array
     */
    protected function filterBlockClasses(array $blockClasses)
    {
        $newMap = $this->getMapping();
        $filtered = array();
        foreach ($blockClasses as $name)
        {
            $name = str_replace('/', '\\', $name);
            $nameLower = strtolower($name);
            $matches = array();

            foreach (array_keys($newMap['building_blocks']) as $class)
            {
                if ($name == $class)
                {
                    $filtered[$class] = $class;

                    break;
                }

                $classLower = strtolower($class);
                if (false !== strpos($classLower, $nameLower))
                {
                    // the name is contained somewhere inside this class, remember for now
                    $matches[] = $class;
                }
            }

            if (0 == count($matches))
            {
                throw new \InvalidArgumentException("There is no building block class matching '$name'. Did you type it correctly?");
            }
            elseif (count($matches) > 1)
            {
                throw new \InvalidArgumentException("The building block name '$name' is ambiguous and matches the following classes: '".implode("', '", $matches).". Please be more specific.");
            }

            $filtered[] = $matches[0];
        }

        return $filtered;
    }

    protected function doDebug(OutputInterface $output, array $blockClasses = array(), $showDetails = false)
    {
        $output->writeln("\n<info>Building blocks overview</info>");
        $newMap = $this->getMapping();
        $table = new Table($output);
        $table
            ->setHeaders(array(
                'Block class',
                'Enabled',
                'Config',
                'Assets',
                'Source',
                'Auto',
            ))
        ;

        foreach ($newMap['building_blocks'] as $class => $config)
        {
            $block = $this->getBlock($class);
            $table->addRow(array(
                $class,
                $config['enabled'] ? 'Yes':'No',
                $config['use_config'] ? 'Yes':'No',
                $config['use_assets'] ? 'Yes':'No',
                $this->buildingBlockSources[$class],
                $block->isAutoInstall() ? 'Yes':'No',
            ));
        }

        $table->render();

        if (count($blockClasses) > 0)
        {
            $showDetails = true;
        }
        if ($showDetails && count($blockClasses) == 0)
        {
            $blockClasses = array_keys($newMap['building_blocks']);
        }

        if (!$showDetails)
        {
            return;
        }

        foreach ($blockClasses as $class)
        {
            $block = $this->getBlock($class);
            $info = $this->getBlockInfo($block);

            $output->writeln("\n<fg=cyan>{$class}:</fg=cyan>");

            $output->writeln("<info>  Bundles:</info>");
            $first = true;
            foreach ($info['bundle_classes'] as $bundle)
            {
                if ($first)
                {
                    $output->writeln("    - <options=bold>{$bundle}</options=bold>");
                    $first = false;
                }
                else
                {
                    $output->writeln("    - $bundle");
                }
            }

            $table = new Table($output);
            $table->setStyle('compact');
            $table->addRow(array("<info> Application config:</info>", ""));
            foreach ($info['config_templates'] as $env => $templates)
            {
                ksort($templates);
                $configPath = ('' == $env) ? 'config' : "config<options=bold>.$env</options=bold>";
                foreach ($templates as $relative => $template)
                {
                    $base = basename($template);
                    $table->addRow(array("   - {$configPath}/{$base}", "  ".$relative));
                }

            }

            $table->addRow(array("<info> Default config:</info>", ""));
            foreach ($info['default_configs'] as $env => $defaults)
            {
                ksort($defaults);
                $configPath = ('' == $env) ? 'config' : "config<options=bold>.$env</options=bold>";
                foreach ($defaults as $relative => $default)
                {
                    $base = basename($default);
                    $table->addRow(array("   - {$configPath}/{$base}", "  ".$relative));
                }
            }

            $table->render();

            $output->writeln("<info>  Assets:</info>");
            foreach ($info['assets'] as $group => $grouped)
            {
                $output->writeln("    <comment>{$group}</comment>", "");
                foreach ($grouped as $asset)
                {
                    $output->writeln("      - {$asset}");
                }
            }
        }
    }
}
