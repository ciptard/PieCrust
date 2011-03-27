<?php

/**
 * A class responsible for baking a PieCrust page.
 */
class PageBaker
{
    protected $pieCrust;
    protected $bakeDir;
    protected $parameters;
    
    protected $wasPaginationDataAccessed;
    /**
     * Gets whether pagination data was accessed during baking.
     */
    public function wasPaginationDataAccessed()
    {
        return $this->wasPaginationDataAccessed;
    }
    
    protected $pageCount;
    /**
     * Gets the number of baked pages.
     */
    public function getPageCount()
    {
        return $this->pageCount;
    }
    
    /**
     * Creates a new instance of PageBaker.
     */
    public function __construct(PieCrust $pieCrust, $bakeDir, array $parameters = array())
    {
        $this->pieCrust = $pieCrust;
        $this->bakeDir = $bakeDir;
        $this->parameters = array_merge(array(
            'copy_assets' => false
        ), $parameters);
    }
    
    /**
     * Bakes the given page. Additional template data can be provided, along with
     * a specific set of posts for the pagination data.
     */
    public function bake(Page $page, array $postInfos = null, array $extraData = null)
    {
        $this->pageCount = 0;
        $this->wasPaginationDataAccessed = false;
        
        $pageRenderer = new PageRenderer($this->pieCrust);
        
        $hasMorePages = true;
        while ($hasMorePages)
        {
            $this->bakeSinglePage($page, $pageRenderer, $postInfos, $extraData);
            
            $paginator = $page->getPaginator();
            $hasMorePages = ($paginator->wasPaginationDataAccessed() and $paginator->hasMorePages());
            if ($hasMorePages)
            {
                $page->setPageNumber($page->getPageNumber() + 1);
                // setPageNumber() resets the page's data, so when we enter bakeSinglePage again
                // in the next loop, we have to re-set the extraData and all other stuff.
            }
        }
        
        $this->pageCount = $page->getPageNumber();
    }
    
    protected function bakeSinglePage(Page $page, PageRenderer $pageRenderer, array $postInfos = null, array $extraData = null)
    {
        // Set the extraData and asset URL remapping before the page's data is computed.        
        if ($extraData != null) $page->setExtraPageData($extraData);
        if ($this->parameters['copy_assets'] === true) $page->setAssetUrlBaseRemap("%url_base%%uri%");
        
        // Set the custom stuff.
        $assetor = $page->getAssetor();
        $paginator = $page->getPaginator();
        if ($postInfos != null) $paginator->setPaginationDataSource($postInfos);
        
        // Render the page.
        $bakedContents = $pageRenderer->get($page);
        
        // Figure out the output HTML path.
        $useDirectory = $page->getConfigValue('pretty_urls');
        if ($useDirectory == null)
        {
            $useDirectory = $this->pieCrust->getConfigValue('site', 'pretty_urls');
        }
        
        $contentType = $page->getConfigValue('content_type');
        if ($contentType != 'html')
        {
            // If this is not an HTML file, don't use a directory as the output
            // (since this would bake it to an 'index.html' file).
            $useDirectory = false;
        }
        
        if ($paginator->wasPaginationDataAccessed() and !$page->getConfigValue('single_page'))
        {
            // If pagination data was accessed, there may be sub-pages for this page,
            // so we need the 'directory' naming scheme to store them (unless this
            // page is forced to a single page).
            $useDirectory = true;
        }
        
        // Figure out the output file/directory for the page.
        if ($useDirectory)
        {
            $bakePath = ($this->bakeDir . 
                         $page->getUri() . 
                         (($page->getUri() == '') ? '' : DIRECTORY_SEPARATOR) . 
                         (($page->getPageNumber() == 1) ? '' : ($page->getPageNumber() . DIRECTORY_SEPARATOR)) .
                         PIECRUST_BAKE_INDEX_DOCUMENT);
        }
        else
        {
            $extension = $this->getBakedExtension($contentType);
            $bakePath = $this->bakeDir . (($page->getUri() == '') ? 'index' : $page->getUri()) . '.' . $extension;
        }
        
        // Copy the page.
        FileSystem::ensureDirectory(dirname($bakePath));
        file_put_contents($bakePath, $bakedContents);
        
        // Copy any used assets for the first sub-page.
        if ($page->getPageNumber() == 1 and $this->parameters['copy_assets'] === true)
        {
            if ($useDirectory)
            {
                $bakeAssetDir = dirname($bakePath) . DIRECTORY_SEPARATOR;
            }
            else
            {
                $bakePathInfo = pathinfo($bakePath);
                $bakeAssetDir = $bakePathInfo['dirname'] . DIRECTORY_SEPARATOR . 
                                (($page->getUri() == '') ? '' : $bakePathInfo['filename']) . DIRECTORY_SEPARATOR;
            }
            
            $assetPaths = $assetor->getAssetPathnames();
            if ($assetPaths != null)
            {
                FileSystem::ensureDirectory($bakeAssetDir);
                foreach ($assetPaths as $assetPath)
                {
                    @copy($assetPath, ($bakeAssetDir . basename($assetPath)));
                }
            }
        }
        
        $this->wasPaginationDataAccessed = ($this->wasPaginationDataAccessed or $paginator->wasPaginationDataAccessed());
    }
    
    protected function getBakedExtension($contentType)
    {
        switch ($contentType)
        {
            case 'text':
                return 'txt';
            default:
                return $contentType;
        }
    }
}
