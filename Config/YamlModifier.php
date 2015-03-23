<?php

namespace C33s\ConstructionKitBundle\Config;

use Symfony\Component\Yaml\Yaml;

class YamlModifier
{
    /**
     * Split the given yaml config file into its main sections/modules, each holding the relevant content.
     * A block usually starts where a first-level key is set, e.g.:
     *
     * # Leading comments are included if they do not contain more than 1 space directly after the "#" symbol
     * # This module will be called "framework"
     * framework:
     *     sub-framework-config:
     *     # this will still be included in the block
     *
     *     # still there as long as no other block has started
     *     some-more-config:
     *
     * # This is also considered an (escaped) module block called "monolog"
     * #monolog:
     * #    sub-monolog-config:
     *
     * Line numbers are zero-based.
     *
     * Returns an array holding elements:
     *  $module => array(
     *      "config_start" => starting line number of block
     *      "config_end"   => ending line number of block
     *      "content"      => YAML text content
     *      "data"         => parsed array from this YAML content block
     *  )
     *
     * @param string $configFile
     *
     * @return array
     */
    public function parseYamlModules($configFile)
    {
        $lines = file($configFile);
        $modules = array();
        $lastModule = null;

        for ($i = 0; $i < count($lines); ++$i)
        {
            $line = rtrim($lines[$i]);

            if (empty($line))
            {
                continue;
            }

            $matches = array();
            if (preg_match('/^([a-zA-Z0-9_\-]+)\:/', $line, $matches) || preg_match('/^#([a-zA-Z0-9_\-]+)\:/', $line, $matches))
            {
                $module = $matches[1];
                $modules[$module]['config_start'] = $i;
                $j = $i;
                while ($j > 0 && preg_match('/^# [a-zA-Z0-9]+/', $lines[$j - 1]))
                {
                    --$j;
                }
                $modules[$module]['config_start'] = $j;

                if (null !== $lastModule)
                {
                    $modules[$lastModule]['config_end'] = $j - 1;
                }

                $lastModule = $module;
            }
        }
        $modules[$lastModule]['config_end'] = $i + 1;

        foreach ($modules as $module => $offsets)
        {
            $modules[$module]['content'] = implode("", array_slice($lines, $offsets['config_start'], $offsets['config_end'] - $offsets['config_start'] + 1));
            $modules[$module]['data'] = Yaml::parse($modules[$module]['content']);
        }

        return $modules;
    }

    /**
     * This adds the given filename to the imports: section of the given yaml file, overwriting the file during the process.
     *
     * @param string $importerFile
     * @param string $filenameToImport
     *
     * @return boolean true if the file was added, false if it already existed
     */
    public function addImportFilenameToImporterFile($importerFile, $filenameToImport)
    {
        if ($this->importerFileHasFilename($importerFile, $filenameToImport))
        {
            return false;
        }

        $data = array();
        if (file_exists($importerFile))
        {
            $data = Yaml::parse(file_get_contents($importerFile));
            if (!is_array($data) || !isset($data['imports']))
            {
                $data = array('imports' => array());
            }
        }

        $data['imports'][] = array('resource' => $filenameToImport);
        $data = $this->sortImports($data);

        $content = "# Please do not edit this file manually. Manual edits will be lost during construction-kit related commands.\n";
        $content .= Yaml::dump($data);
        file_put_contents($importerFile, $content);

        return true;
    }

    /**
     * Check if the given yaml file exists and includes the given filename in its imports: section.
     *
     * @param string $importerFile
     * @param string $filenameToImport
     *
     * @return boolean
     */
    public function importerFileHasFilename($importerFile, $filenameToImport)
    {
        if (!file_exists($importerFile))
        {
            return false;
        }

        $data = Yaml::parse(file_get_contents($importerFile));

        return $this->dataContainsImportFile($data, $filenameToImport);
    }

    /**
     * Check if the given data is an array containing a Symfony config import statement for the given file name.
     *
     * @param array $data
     * @param string $filename
     *
     * @return boolean
     */
    public function dataContainsImportFile($data, $filename)
    {
        if (!is_array($data) || !isset($data['imports']))
        {
            return false;
        }

        foreach ($data['imports'] as $value)
        {
            if ($value['resource'] == $filename)
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Sort the "imports" key children of the given array by resource name.
     *
     * @param array $data
     *
     * @return array
     */
    protected function sortImports(array $data)
    {
        if (!isset($data['imports']) || !is_array($data['imports']))
        {
            return $data;
        }

        usort($data['imports'], function ($a, $b) {
            if ($a['resource'] > $b['resource'])
            {
                return 1;
            }
            elseif ($a['resource'] < $b['resource'])
            {
                return -1;
            }

            return 0;
        });

            return $data;
    }

    /**
     * Add the given parameter name and value to the given parameters.yml file.
     *
     * @param string $filename
     * @param string $name
     * @param mixed $value
     * @param boolean $preserveFormatting
     * @param string $addComment
     */
    public function addParameterToFile($filename, $name, $value, $preserveFormatting, $addComment = null)
    {
        $content = file_get_contents($filename);
        $data = Yaml::parse($content);

        if ($preserveFormatting)
        {
            $content = rtrim($content);

            $c = Yaml::dump(array(
                'parameters' => array(
                    $name => $value,
                ),
            ), 99);

            list(, $c) = explode("\n", $c, 2);

            if (null !== $addComment)
            {
                $content .= "\n\n    # ".$addComment;
            }
            $content .= "\n".$c;
        }
        else
        {
            $data['parameters'][$name] = $value;
            $content = Yaml::dump($data, 99);
        }

        file_put_contents($filename, $content);
    }
}
