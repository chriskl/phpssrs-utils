<?php
/**
 * Created by PhpStorm.
 * User: chris.kings-lynne
 * Date: 9/30/13
 * Time: 11:08 AM
 */


use \PhpSsrs\ReportingService2010 as Rs;

class RsSync
{
    /**
     * Root folder
     * @var string
     */
    CONST ROOT = '/';

    /**
     * Default socket timeout
     */
    CONST DEFAULT_SOCKET_TIMEOUT = 600;

    /**
     * Current path
     * @var SplStack
     */
    protected $path;

    /**
     * Details of currently processing element
     * @var SplStack
     */
    protected $stack;

    /**
     * SSRS SOAP client
     * @var Rs\ReportingService2010
     */
    protected $rs;

    /**
     * Properties that can be substituted into the XML
     * @var array
     */
    public $properties = [];

    /**
     * Layout
     * @var string
     */
    public $layout;

    /**
     * Location
     * @var string
     */
    public $location;

    /**
     * Username
     * @var string
     */
    public $username;

    /**
     * Password
     * @var string
     */
    public $password;

    /**
     * Execute the rssync command
     * @param array $args Command line arguments
     */
    public static function start(array $args)
    {
        $rssync = new RsSync();
        $rssync->execute($args);
    }

    public function __construct()
    {
        $this->path = new SplStack();
        $this->stack = new SplStack();
    }

    /**
     * Process arguments
     * @param array $args Argument array (from getopt)
     * @throws InvalidArgumentException
     */
    public function processArgs(array $args)
    {
        // Layout
        if (!array_key_exists('l', $args)) {
            throw new InvalidArgumentException("Missing layout argument: -l");
        }
        if (!is_readable($args['l'])) {
            throw new InvalidArgumentException("Layout not found: " . $args['l']);
        }
        $this->layout = $args['l'];

        // Reporting Service
        if (!array_key_exists('h', $args)) {
            throw new InvalidArgumentException("Missing reporting service URL: -h");
        }

        if (substr($args['h'], -1, 1) == '/') {
            $this->location = $args['h'] . 'ReportService2010.asmx';
        } else {
            $this->location = $args['h'] . '/ReportService2010.asmx';
        }

        // Username
        if (!array_key_exists('u', $args)) {
            throw new InvalidArgumentException("Missing reporting service username: -u");
        }
        $this->username = $args['u'];

        // Password
        if (!array_key_exists('p', $args)) {
            throw new InvalidArgumentException("Missing reporting service password: -p");
        }
        $this->password = $args['p'];

        // Root folder
        if (array_key_exists('r', $args)) {
            $root = $args['r'];
            if (substr($root, 0, 1) != '/') {
                throw new InvalidArgumentException("Root path must begin with '/'.  Try: -r " . escapeshellarg(
                        "/{$root}"
                    ));
            }

            $this->path->push($root);
        } else {
            // Default to root folder
            $this->path->push(self::ROOT);
        }


        // Properties
        if (array_key_exists('d', $args)) {
            // Check for single property
            if (is_string($args['d'])) {
                $args['d'] = [$args['d']];
            }
            foreach ($args['d'] as $p) {
                list($key, $val) = explode('=', $p, 2);
                $this->properties[$key] = $val;
            }
        }
    }

    public function execute($args)
    {
        try {
            $this->processArgs($args);

            $this->rs = $this->getClient();

            // Ensure that the root exists
            $parts = explode('/', $this->path->top());
            $parent = '/';
            foreach ($parts as $p) {
                if ($p == '') {
                    continue;
                }
                fwrite(
                    STDOUT,
                    sprintf(
                        'Creating root folder: %s%s%s',
                        $parent,
                        ($parent == self::ROOT ? '' : '/'),
                        $p
                    ) . PHP_EOL
                );
                $folder = new Rs\CreateFolder($p, $parent, []);
                try {
                    $this->rs->CreateFolder($folder);
                } catch (SoapFault $e) {
                    // Ignore already exists exceptions
                    if (strpos(
                            $e->getMessage(),
                            'Microsoft.ReportingServices.Diagnostics.Utilities.ItemAlreadyExistsException'
                        ) === false
                    ) {
                        throw $e;
                    }
                } catch (Exception $e) {
                    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
                }

                if ($parent == self::ROOT) {
                    $parent .= $p;
                } else {
                    $parent .= '/' . $p;
                }
            }

            $this->processLayout();
        } catch (Exception $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);

            throw $e;
        }
    }

    /**
     * Get array hash value, or default if missing
     * @param string $key Key value
     * @param array $array Target array
     * @param mixed $default Value to return if key is not in array
     * @return mixed Array value, or default if not present
     */
    protected function getAttrDefault($key, array $array, $default)
    {
        if (array_key_exists($key, $array)) {
            return $array[$key];
        } else {
            return $default;
        }
    }

    /**
     * Substitute properties into attributes
     * @param string[] $attrs Attribute array
     * @return string[] Attribute array with properties substituted
     */
    protected function substituteProperties(array $attrs)
    {
        // Shortcut
        if (count($this->properties) == 0) {
            return $attrs;
        }

        $tmp = [];
        foreach ($attrs as $k => $v) {
            foreach ($this->properties as $p => $replace) {
                $search = "\${{$p}}";
                $v = str_ireplace($search, $replace, $v);
            }
            $tmp[$k] = $v;
        }

        return $tmp;
    }

    /**
     * Process start elements
     * @param $parser
     * @param $name
     * @param $attrs
     */
    protected function startElement($parser, $name, $attrs)
    {
        // Interpolate properties into the attributes
        $attrs = $this->substituteProperties($attrs);

        switch ($name) {
            case 'ROLE':
                $role = new Rs\CreateRole($attrs['NAME'], $attrs['DESCRIPTION'], []);
                $this->stack->push($role);
                break;
            case 'TASK':
                $role = $this->stack->pop();
                $role->TaskIDs[] = $attrs['ID'];
                $this->stack->push($role);
                break;
            case 'FOLDER':
                fwrite(
                    STDOUT,
                    sprintf(
                        'Creating folder: %s%s%s',
                        $this->path->top(),
                        ($this->path->top() == self::ROOT ? '' : '/'),
                        $attrs['NAME']
                    ) . PHP_EOL
                );
                $folder = new Rs\CreateFolder($attrs['NAME'], $this->path->top(), []);
                try {
                    $this->rs->CreateFolder($folder);
                } catch (SoapFault $e) {
                    // Ignore already exists exceptions
                    if (strpos(
                            $e->getMessage(),
                            'Microsoft.ReportingServices.Diagnostics.Utilities.ItemAlreadyExistsException'
                        ) === false
                    ) {
                        throw $e;
                    }
                } catch (Exception $e) {
                    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
                }

                // Push the folder onto the queue.  In SSRS root folder must be '/', but no other folders can have trailing slashes :(
                if ($this->path->top() != self::ROOT) {
                    $this->path->push($this->path->top() . '/' . $attrs['NAME']);
                } else {
                    $this->path->push($this->path->top() . $attrs['NAME']);
                }
                break;
            case 'DATASOURCE':
                fwrite(
                    STDOUT,
                    sprintf(
                        'Creating data source: %s%s%s',
                        $this->path->top(),
                        ($this->path->top() == self::ROOT ? '' : '/'),
                        $attrs['NAME']
                    ) . PHP_EOL
                );

                // Overwrite?
                $overwrite = $this->getAttrDefault('OVERWRITE', $attrs, 'true') == 'true';

                // Build definition
                $definition = new Rs\DataSourceDefinition();
                $definition->ConnectString = $this->getAttrDefault('CONNECTSTRING', $attrs, '');
                $definition->Extension = $this->getAttrDefault('EXTENSION', $attrs, 'SQL');
                $definition->Enabled = $this->getAttrDefault('ENABLED', $attrs, 'true') == 'true';
                $definition->UseOriginalConnectString = $this->getAttrDefault(
                        'ORIGINALCONNECTSTRINGEXPRESSIONBASED',
                        $attrs,
                        'false'
                    ) == 'true';
                $definition->OriginalConnectStringExpressionBased = $this->getAttrDefault(
                        'ORIGINALCONNECTSTRINGEXPRESSIONBASED',
                        $attrs,
                        'false'
                    ) == 'true';
                $definition->WindowsCredentials = $this->getAttrDefault(
                        'WINDOWSCREDENTIALS',
                        $attrs,
                        'false'
                    ) == 'true';
                $definition->ImpersonateUser = $this->getAttrDefault('IMPERSONATEUSER', $attrs, 'false') == 'true';
                $credentialRetrieval = $this->getAttrDefault('CREDENTIALRETRIEVAL', $attrs, 'Prompt');
                switch (strtoupper($credentialRetrieval)) {
                    case 'PROMPT':
                        $definition->Prompt = $this->getAttrDefault('PROMPT', $attrs, '');
                        $definition->CredentialRetrieval = Rs\CredentialRetrievalEnum::Prompt;
                        break;
                    case 'STORE':
                        $definition->CredentialRetrieval = Rs\CredentialRetrievalEnum::Store;
                        $definition->UserName = $this->getAttrDefault('USERNAME', $attrs, '');
                        $definition->Password = $this->getAttrDefault('PASSWORD', $attrs, '');
                        break;
                    case 'INTEGRATED':
                        $definition->CredentialRetrieval = Rs\CredentialRetrievalEnum::Integrated;
                        break;
                    case 'NONE':
                        $definition->CredentialRetrieval = Rs\CredentialRetrievalEnum::None;
                        break;
                    default:
                        throw new Exception("Invalid credential retrieval: " . $credentialRetrieval);
                }

                $dataSource = new Rs\CreateDataSource($attrs['NAME'], $this->path->top(),
                    $overwrite, $definition, []);
                try {
                    $this->rs->CreateDataSource($dataSource);
                } catch (Exception $e) {
                    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
                }

                break;
            case 'DATASET':
                fwrite(
                    STDOUT,
                    sprintf(
                        'Creating dataset: %s%s%s',
                        $this->path->top(),
                        ($this->path->top() == self::ROOT ? '' : '/'),
                        $attrs['NAME']
                    ) . PHP_EOL
                );

                $dataset = new Rs\CreateCatalogItem();
                $dataset->ItemType = 'DataSet';
                $dataset->Name = $attrs['NAME'];
                $dataset->Parent = $this->path->top();
                $dataset->Overwrite = true;
                if (!is_readable($attrs['DEFINITION'])) {
                    throw new Exception("File not found: " . $attrs['DEFINITION']);
                }

                // Change definition to point to live data source, if necessary
                $definition = file_get_contents($attrs['DEFINITION']);
                if (array_key_exists('DATASOURCEREF', $attrs)) {
                    $dsRef = $attrs['DATASOURCEREF'];
                    $rootRef = $this->path->bottom();
                    if ($rootRef != self::ROOT) {
                        $dsRef = $rootRef . $dsRef;
                    }

                    fwrite(
                        STDOUT,
                        sprintf(
                            '    Linking data source: %s',
                            $dsRef
                        ) . PHP_EOL
                    );

                    $xml = new SimpleXMLElement($definition);
                    $xml->DataSet->Query->DataSourceReference = $dsRef;

                    $definition = $xml->asXML();
                }

                $dataset->Definition = $definition;
                $dataset->Properties = [];
                try {
                    $response = $this->rs->CreateCatalogItem($dataset);
                } catch (Exception $e) {
                    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
                }

                break;
            case 'REPORT':
                fwrite(
                    STDOUT,
                    sprintf(
                        'Creating report: %s%s%s',
                        $this->path->top(),
                        ($this->path->top() == self::ROOT ? '' : '/'),
                        $attrs['NAME']
                    ) . PHP_EOL
                );

                $report = new Rs\CreateCatalogItem();
                $report->ItemType = 'Report';
                $report->Name = $attrs['NAME'];
                $report->Parent = $this->path->top();
                $report->Overwrite = true;
                if (!is_readable($attrs['DEFINITION'])) {
                    throw new Exception("File not found: " . $attrs['DEFINITION']);
                }
                $report->Definition = file_get_contents($attrs['DEFINITION']);
                $report->Properties = [];
                try {
                    $response = $this->rs->CreateCatalogItem($report);
                } catch (Exception $e) {
                    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
                }

                // Set data source
                if (array_key_exists('DATASOURCEREF', $attrs)) {
                    $ref = new Rs\DataSourceReference();
                    $dsRef = $attrs['DATASOURCEREF'];
                    $rootRef = $this->path->bottom();
                    if ($rootRef != self::ROOT) {
                        $dsRef = $rootRef . $dsRef;
                    }
                    $ref->Reference = $dsRef;

                    fwrite(
                        STDOUT,
                        sprintf(
                            '    Linking data source: %s => %s',
                            $attrs['DATASOURCEREFNAME'],
                            $dsRef
                        ) . PHP_EOL
                    );

                    $source = new Rs\DataSource();
                    $source->DataSourceReference = $ref;
                    $source->Name = $attrs['DATASOURCEREFNAME'];

                    $sources = [];
                    $sources[0] = $source;
                    $set = new Rs\SetItemDataSources();
                    $set->ItemPath = $this->path->top() . '/' . $report->Name;
                    $set->DataSources = $sources;

                    try {
                        $this->rs->SetItemDataSources($set);
                    } catch (Exception $e) {
                        fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
                    }
                }

                $this->stack->push(array($this->path->top(), $name, $attrs));

                break;
            case 'ITEMREFERENCE':
                $itemRef = $attrs['REFERENCE'];
                $rootRef = $this->path->bottom();

                if ($rootRef != self::ROOT) {
                    $itemRef = $rootRef . $itemRef;
                }
                fwrite(
                    STDOUT,
                    sprintf(
                        '    Linking reference: %s => %s',
                        $attrs['NAME'],
                        $itemRef
                    ) . PHP_EOL
                );

                $itemReference = new Rs\ItemReference();
                $itemReference->Name = $attrs['NAME'];
                $itemReference->Reference = $itemRef;

                // Get the report we're in
                $report = $this->stack->top();

                $itemReferences = new Rs\SetItemReferences();
                $itemReferences->ItemPath = $report[0] . '/' . $report[2]['NAME'];
                // TODO: set all references in bulk!
                $itemReferences->ItemReferences = [$itemReference];

                try {
                    $response = $this->rs->SetItemReferences($itemReferences);
                } catch (Exception $e) {
                    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
                }

                break;
        }
    }

    /**
     * Process end XML elements
     * @param resource $parser Xml parser
     * @param string $name Element name
     */
    protected function endElement($parser, $name)
    {
        switch ($name) {
            case 'ROLE':
                fwrite(STDOUT, 'Creating role');
                $role = $this->stack->pop();
                $response = $this->rs->CreateFolder(new Rs\CreateFolder('hi', '/', []));
                //$response = $this->rs->CreateRole($role);
                //var_dump($response);
                exit;
                break;
            case 'FOLDER':
                // Pop the folder off the queue
                $this->path->pop();
                break;
            case 'REPORT':
                $this->stack->pop();
                break;
        }
    }

    /**
     *
     * @param $layout Layout XML file
     */
    public function processLayout()
    {
        $layout = $this->layout;
        //$this->validateLayout($layout);


        $parser = xml_parser_create();
        global $depth;
        $depth = array((int)$parser => 0);
        xml_set_element_handler($parser, array($this, 'startElement'), array($this, 'endElement'));
        if (!($fp = fopen($layout, 'r'))) {
            throw new Exception("Could not open layout: " . $layout);
        }

        while ($data = fread($fp, 4096)) {
            if (!xml_parse($parser, $data, feof($fp))) {
                throw new Exception(sprintf(
                    "XML error: %s at line %d",
                    xml_error_string(xml_get_error_code($parser)),
                    xml_get_current_line_number($parser)
                ));
            }
        }

        xml_parser_free($parser);
    }


    protected function validateLayout($layout)
    {
        $doc = new DOMDocument();
        if ($doc->load($layout) === false) {
            $lastErr = libxml_get_last_error();
            throw new Exception($lastErr->message, $lastErr->code);
        }

        if ($doc->schemaValidate('etc/layout.xsd') === false) {
            $lastErr = libxml_get_last_error();
            throw new Exception($lastErr->message, $lastErr->code);
        }
    }

    /**
     * @return ReportingService2010
     */
    protected function getClient()
    {
        ini_set('default_socket_timeout', self::DEFAULT_SOCKET_TIMEOUT);

        // Replace WSDL URL with your URL, or even better a locally saved version of the file.
        $rs = new Rs\ReportingService2010([
                'soap_version' => SOAP_1_2,
                'compression' => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_NONE,
                'keep_alive' => true,
                'features' => SOAP_SINGLE_ELEMENT_ARRAYS & SOAP_USE_XSI_ARRAY_TYPE,
                'location' => $this->location,
                //'uri' => 'http://schemas.microsoft.com/sqlserver/2010/06/30/reporting/reportingservices',
                //'style' => SOAP_DOCUMENT,
                //'use' => SOAP_LITERAL,
                'login' => $this->username,
                'password' => $this->password,
                //'proxy_host' => 'localhost',
                //'proxy_port' => 8888
            ], dirname(
                __FILE__
            ) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'ReportService2010.wsdl');

        return $rs;
    }
}
