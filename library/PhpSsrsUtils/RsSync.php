<?php
/**
 * Created by PhpStorm.
 * User: chris.kings-lynne
 * Date: 9/30/13
 * Time: 11:08 AM
 */


use \PhpSsrs\ReportingService2005 as Rs;

class RsSync
{
    /**
     * Root folder
     * @var string
     */
    CONST ROOT = '/';

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
     * @var Rs\ReportingService2005
     */
    protected $rs;

    /**
     * Properties that can be substituted into the XML
     * @var array
     */
    protected $properties = [];

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
        $this->path->push(self::ROOT);

        $this->stack = new SplStack();

        $this->properties = [
            'datasource.Navigate.connectString' => 'Data Source=(local);Initial Catalog=Accord',
            'datasource.Navigate.userName' => 'accord',
            'datasource.Navigate.password' => 'accord'
        ];
    }

    public function execute($args)
    {
        try {
            $this->rs = $this->getClient();

            $layout = 'etc/layout.xml';

            $this->processLayout($layout);
        } catch (Exception $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
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
                $this->rs->CreateDataSource($dataSource);
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

                // Different behaviour depending on client
                switch (get_class($this->rs)) {
                    case 'PhpSsrs\ReportingService2005\ReportingService2005':
                        $report = new Rs\CreateReport();
                        $report->Report = $attrs['NAME'];
                        $report->Parent = $this->path->top();
                        $report->Overwrite = true;
                        if (!is_readable($attrs['DEFINITION'])) {
                            throw new Exception("File not found: " . $attrs['DEFINITION']);
                        }
                        $report->Definition = file_get_contents($attrs['DEFINITION']);
                        $report->Properties = [];
                        $response = $this->rs->CreateReport($report);

                        // Set data source
                        if (array_key_exists('DATASOURCEREF', $attrs)) {
                            $ref = new Rs\DataSourceReference();
                            $ref->Reference = $attrs['DATASOURCEREF'];

                            $source = new Rs\DataSource();
                            $source->DataSourceReference = $ref;
                            $source->Name = $attrs['DATASOURCEREFNAME'];

                            $sources = [];
                            $sources[0] = $source;
                            $set = new Rs\SetItemDataSources();
                            $set->Item = $this->path->top() . '/' . $report->Report;
                            $set->DataSources = $sources;

                            $this->rs->SetItemDataSources($set);
                        }
                        break;
                    case 'PhpSsrs\ReportingService2005\ReportingService2010':
                        $report = new Rs\CreateCatalogItem();
                        $report->ItemType = 'Report';
                        $report->Name = $attrs['NAME'];
                        $report->Parent = $this->path->top();
                        $report->Overwrite = true;
                        $report->Definition = file_get_contents($attrs['DEFINITION']);
                        $report->Properties = [];
                        $response = $this->rs->CreateCatalogItem($report);

                        // Set data source
                        if (array_key_exists('DATASOURCEREF', $attrs)) {
                            $ref = new Rs\DataSourceReference();
                            $ref->Reference = $attrs['DATASOURCEREF'];

                            $source = new Rs\DataSource();
                            $source->DataSourceReference = $ref;
                            $source->Name = $name;

                            $sources = [];
                            $sources[0] = $source;

                            $set = new Rs\SetItemDataSources();
                            $set->ItemPath = $response->ItemInfo->Path;
                            $set->DataSources = $sources;
                            $this->rs->SetItemDataSources($set);
                        }
                        break;
                }

                break;
        }
    }

    protected function endElement($parser, $name)
    {
        switch ($name) {
            case 'ROLE':
                fwrite(STDOUT, 'Creating role');
                $role = $this->stack->pop();
                $response = $this->rs->CreateFolder(new Rs\CreateFolder('hi', '/', []));
                //$response = $this->rs->CreateRole($role);
                var_dump($response);
                exit;
                break;
            case 'FOLDER':
                // Pop the folder off the queue
                $this->path->pop();
        }
    }

    /**
     *
     * @param $layout Layout XML file
     */
    public function processLayout($layout)
    {
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
     * @return ReportingService2005
     */
    protected function getClient()
    {
        // Replace WSDL URL with your URL, or even better a locally saved version of the file.
        $rs = new Rs\ReportingService2005([
            'soap_version' => SOAP_1_2,
            'compression' => true,
            'exceptions' => true,
            //'cache_wsdl' => WSDL_CACHE_BOTH,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'keep_alive' => true,
            'features' => SOAP_SINGLE_ELEMENT_ARRAYS & SOAP_USE_XSI_ARRAY_TYPE,
            //'location' => 'http://hksql-t01.services.local/reportserver/ReportService2005.asmx',
            //'uri' => 'http://schemas.microsoft.com/sqlserver/2005/06/30/reporting/reportingservices',
            //'style' => SOAP_DOCUMENT,
            //'use' => SOAP_LITERAL,
            'login' => 'NAVITAS\\chris.kings-lynne',
            'password' => 'Minecraft1.7.1',
            //'proxy_host' => 'localhost',
            //'proxy_port' => 8888
        ], 'ReportService2005.wsdl');

        return $rs;
    }
}
