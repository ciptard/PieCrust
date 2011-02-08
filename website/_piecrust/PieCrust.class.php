<?php

/**
 *  The main PieCrust app class.
 *
 */

define('PIECRUST_APP_DIR', dirname(__FILE__) . DIRECTORY_SEPARATOR);
define('PIECRUST_ROOT_DIR', dirname(PIECRUST_APP_DIR) . DIRECTORY_SEPARATOR);

define('PIECRUST_INDEX_PAGE_NAME', '_index');
define('PIECRUST_CONFIG_PATH', '_content/config.yml');
define('PIECRUST_CONTENT_TEMPLATES_DIR', '_content/templates/');
define('PIECRUST_CONTENT_PAGES_DIR', '_content/pages/');
define('PIECRUST_CACHE_COMPILED_TEMPLATES_DIR', '_cache/templates_c/');
define('PIECRUST_CACHE_TEMPLATES_CACHE_DIR', '_cache/templates/');

define('PIECRUST_DEFAULT_TEMPLATE_NAME', 'default');
define('PIECRUST_DEFAULT_TEMPLATE_ENGINE', 'Twig');

require_once('IFormatter.class.php');
require_once('ITemplateEngine.class.php');
require_once('PluginLoader.class.php');
require_once('PieCrustException.class.php');

require_once('libs/sfyaml/lib/sfYamlParser.php');


class PieCrust
{
    const VERSION = '0.0.1';
    
    protected $urlBase;
    
    protected $templatesDir;
    
    public function getTemplatesDir()
    {
        if ($this->templatesDir === null)
            $this->setTemplatesDir(PIECRUST_ROOT_DIR . str_replace('/', DIRECTORY_SEPARATOR, PIECRUST_CONTENT_TEMPLATES_DIR));
        return $this->templatesDir;
    }
    
    public function setTemplatesDir($dir)
    {
		$this->templatesDir = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;
        if (is_dir($this->templatesDir) === false)
            throw new PieCrustException('The templates directory doesn\'t exist: ' . $this->templatesDir);
    }
    
    protected $pagesDir;
    
    public function getPagesDir()
    {
        if ($this->pagesDir === null)
            $this->setPagesDir(PIECRUST_ROOT_DIR . str_replace('/', DIRECTORY_SEPARATOR, PIECRUST_CONTENT_PAGES_DIR));
        return $this->pagesDir;
    }
    
    public function setPagesDir($dir)
    {
        $this->pagesDir = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;
        if (is_dir($this->pagesDir) === false)
            throw new PieCrustException('The pages directory doesn\'t exist: ' . $this->pagesDir);
    }
    
    protected $compiledTemplatesDir;
    
    public function getCompiledTemplatesDir()
    {
        if ($this->compiledTemplatesDir === null)
            $this->setCompiledTemplatesDir(PIECRUST_ROOT_DIR . str_replace('/', DIRECTORY_SEPARATOR, PIECRUST_CACHE_COMPILED_TEMPLATES_DIR));
        return $this->compiledTemplatesDir;
    }
    
    public function setCompiledTemplatesDir($dir)
    {
		$this->compiledTemplatesDir = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;
		if (is_writable($this->compiledTemplatesDir) === false)
			throw new PieCrustException('The compiled templates directory must be writable: ' . $this->compiledTemplatesDir);
    }
    
    protected $templatesCacheDir;
    
    public function getTemplatesCacheDir()
    {
        if ($this->templatesCacheDir === null)
            $this->setTemplatesCacheDir(PIECRUST_ROOT_DIR . str_replace('/', DIRECTORY_SEPARATOR, PIECRUST_CACHE_TEMPLATES_CACHE_DIR));
        return $this->templatesCacheDir;
    }
    
    public function setTemplatesCacheDir($dir)
    {
        $this->templatesCacheDir = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;
        if (is_writable($this->templatesCacheDir) === false)
            throw new PieCrustException('The cached templates directory must be writable: ' . $this->templatesCacheDir);
    }
    
    protected $config;
    
    public function getConfig()
    {
        if ($config == null)
        {
            $yamlParser = new sfYamlParser();
            try
            {
                $config = $yamlParser->parse(file_get_contents(PIECRUST_ROOT_DIR . PIECRUST_CONFIG_PATH));
                if (!isset($config['site']))	// Define the 'site' configuration to prevent having to test for its 
                    $config['site'] = array();	// existence every time we need to test for a key.
                $config['url_base'] = $this->urlBase;
            }
            catch (InvalidArgumentException $e)
            {
                throw new PieCrustException('An error was found in the PieCrust configuration file: ' . $e->getMessage());
            }
        }
        return $config;
    }
    
    protected $formattersLoader;
    
    public function getFormattersLoader()
    {
        if ($this->formattersLoader == null)
        {
            $this->formattersLoader = new PluginLoader(
                                            'IFormatter',
                                            PIECRUST_APP_DIR . 'formatters',
                                            create_function('$p1, $p2', 'return $p1->getPriority() < $p2->getPriority();'));
        }
        return $this->formattersLoader;
    }
    
    public function __construct($urlBase = null)
    {
        if ($urlBase === null)
        {
            $this->urlBase = '/';
        }
        else
        {
            $this->urlBase = rtrim($urlBase, '/') . '/';
        }
    }
    
    public function run()
    {
        $requestedUri = $this->getRequestUri();
        $requestedPath = $this->getRequestedPath($requestedUri);
        
        $pageContents = file_get_contents($requestedPath);
        $pageConfig = $this->getPageConfig($pageContents);
        
        $pageExtension = pathinfo($requestedPath, PATHINFO_EXTENSION);
        $formattedPageContents = $this->getFormattedPageContents($pageContents, $pageExtension);
        
        $pageData = array(
                            'content' => $formattedPageContents,
                            'page' => $this->getPageData($pageConfig),
                            'site' => $this->getSiteData($this->getConfig()),
                            'piecrust' => $this->getGlobalData(),
                            'helpers' => $this->getHelpersData()
                         );
        $this->renderPage($pageConfig, $pageData);
    }
    
    protected function getRequestUri()
    {
        $config = $this->getConfig();
        if (!isset($config['site']['pretty_urls']) ||
            $config['site']['pretty_urls'] != true)
        {
            // Using standard query (no pretty URLs / URL rewriting)
            return $_SERVER['QUERY_STRING'];
        }
        
        $requestUri = null;
        if (isset($_SERVER['IIS_WasUrlRewritten']) &&
            $_SERVER['IIS_WasUrlRewritten'] == '1' &&
            isset($_SERVER['UNENCODED_URL']) &&
            $_SERVER['UNENCODED_URL'] != '')
        {
            // IIS7 rewriting module.
            $requestUri = $_SERVER['UNENCODED_URL'];
			if (strlen($this->urlBase) > 1)
                $requestUri = substr($requestUri, strlen($this->urlBase) - 1);
        }
        elseif (isset($_SERVER['REQUEST_URI']))
        {
            // Apache mod_rewrite.
            $requestUri = $_SERVER['REQUEST_URI'];
            if (strlen($this->urlBase) > 1)
                $requestUri = substr($requestUri, strlen($this->urlBase) - 1);
        }
        if ($requestUri == null)
        {
            die ("PieCrust can't figure out the request URI. It may be because you're running a non supported web server (PieCrust currently supports IIS 7.0+ and Apache");
        }
        return $requestUri;
    }
    
    protected function getRequestedPath($localUrl)
    {
        $requestedUrl = ltrim($localUrl, '/');
        if ($requestedUrl === '')
            $requestedUrl = PIECRUST_INDEX_PAGE_NAME;
        $requestedPathPattern = $this->getPagesDir() . str_replace('/', DIRECTORY_SEPARATOR, $requestedUrl) . '.*';
        $requestedPath = glob($requestedPathPattern, GLOB_NOSORT|GLOB_ERR);
        if ($requestedPath === false)
            throw new PieCrustException('An error occured while trying to find the requested article: ' . $requestedUrl);
        $pathCount = count($requestedPath);
        if ($pathCount == 0)
            throw new PieCrustException('The requested article was not found: ' . $requestedUrl);
        if ($pathCount > 1)
            throw new PieCrustException('More than one file was found for the requested article: ' . $requestedUrl);
        
        return $requestedPath[0];
    }
    
    protected function getPageConfig(&$pageContents)
    {
        $yamlHeaderMatches = array();
        $hasYamlHeader = preg_match('/^(---\s*\n.*?\n?)^(---\s*$\n?)/m', $pageContents, $yamlHeaderMatches);
        if ($hasYamlHeader == true)
        {
            $yamlHeader = substr($pageContents, 0, strlen($yamlHeaderMatches[1]));
            $pageContents = substr($pageContents, strlen($yamlHeaderMatches[0]));
            $yamlParser = new sfYamlParser();
            try
            {
                $pageConfig = $yamlParser->parse($yamlHeader);
            }
            catch (InvalidArgumentException $e)
            {
                throw new PieCrustException('An error occured while reading the YAML header: ' . $e->getMessage());
            }
        }
        else
        {
            $pageConfig = array();
        }
        return $pageConfig;
    }
    
    protected function getFormattedPageContents($pageContents, $pageExtension)
    {
        $unFormatted = true;
        $formattedPageContents = $pageContents;
        foreach ($this->getFormattersLoader()->getPlugins() as $formatter)
        {
            $formatter->initialize($this->getConfig());
            if ($formatter->supportsExtension($pageExtension, $unFormatted))
            {
                $formattedPageContents = $formatter->format($formattedPageContents);
                $unFormatted = false;
            }
        }
        return $formattedPageContents;
    }
    
    protected function getPageData($pageConfig)
    {
        return array(
            'title' => $pageConfig['title']
        );
    }
    
    protected function getSiteData($appConfig)
    {
        $siteConfig = $appConfig['site'];
        if (isset($siteConfig))
        {
            return array(
                'title' => $siteConfig['title'],
                'root' => $appConfig['url_base']
            );
        }
        else
        {
            return array();
        }
    }
    
    protected function getGlobalData()
    {
        return array(
            'version' => PieCrust::VERSION
        );
    }

    protected function getHelpersData()
    {
        
    }
    
    protected function renderPage($pageConfig, $pageData)
    {
        $config = $this->getConfig();
        $templateEngineName = (isset($config['site']['template_engine']) ? $config['site']['template_engine'] : PIECRUST_DEFAULT_TEMPLATE_ENGINE);
        $templateEngineClass = $templateEngineName . 'TemplateEngine';
        require_once(PIECRUST_APP_DIR . 'template-engines/' . $templateEngineClass . '.class.php');
        $reflector = new ReflectionClass($templateEngineClass);
        $templateEngine = $reflector->newInstance();
        $templateEngine->initialize($this->getConfig());
		
		// Define the default template if none was specified.
		if (!isset($pageConfig['layout']))
            $pageConfig['layout'] = PIECRUST_DEFAULT_TEMPLATE_NAME;
        echo $templateEngine->renderPage($this, $pageConfig, $pageData);
    }
}