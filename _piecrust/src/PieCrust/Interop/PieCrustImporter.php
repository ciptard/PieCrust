<?php

namespace PieCrust\Interop;

use \Exception;
use PieCrust\IPieCrust;
use PieCrust\PieCrustDefaults;
use PieCrust\PieCrustException;
use PieCrust\Interop\Importers\IImporter;
use PieCrust\IO\FileSystem;
use PieCrust\Plugins\PluginLoader;


/**
 * A class that bootstraps the importer classes to import content into a PieCrust website.
 */
class PieCrustImporter
{
    protected $pieCrust;
    protected $logger;

    /**
     * Creates a new instance of PieCrustImporter.
     */
    public function __construct(IPieCrust $pieCrust, $logger = null)
    {
        $this->pieCrust = $pieCrust;

        if ($logger == null)
        {
            require_once 'Log.php';
            $logger = \Log::singleton('null', '', '');
        }
        $this->logger = $logger;
    }

    /**
     * Gets the known importers.
     */
    public function getImporters()
    {
        return $this->pieCrust->getPluginLoader()->getImporters();
    }
    
    /**
     * Imports content at the given source, using the given importer format.
     */
    public function import($format, $source)
    {
        // Find the importer that matches the given name and run the import.
        foreach ($this->getImporters() as $importer)
        {
            if ($importer->getName() == $format)
            {
                $this->doImport($this->pieCrust, $importer, $source);
                return;
            }
        }
        
        throw new PieCrustException("Importer format '{$format} ' is unknown.");
    }
    
    protected function doImport(IImporter $importer, $source)
    {
        $log->info("Importing '{$source}' using '{$importer->getName()}'");

        $importer->open($source);
        $importer->importPages($this->pieCrust->getPagesDir());
        $importer->importPosts($this->pieCrust->getPostsDir(), $this->pieCrust->getConfig()->getValue('site/posts_fs'));
        $importer->close();
    }
}
