<?php
/**
 * Created by PhpStorm.
 * User: chris.kings-lynne
 * Date: 9/30/13
 * Time: 11:08 AM
 */

use \PhpSsrs\ReportingService2010\ReportingService2010;

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
     * @var ReportingService2010
     */
    protected $rs;

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
    }

    public function execute($args)
    {
        try {
            $this->rs = $this->getClient();

            $layout = 'etc/layout.xml';

            $this->processLayout($layout);
        } catch (Exception $e) {
            fwrite(STDERR, $e->getMessage());
        }
    }

    /**
     * Process start elements
     * @param $parser
     * @param $name
     * @param $attrs
     */
    protected function startElement($parser, $name, $attrs)
    {
        switch ($name) {
            case 'ROLE':
                $role = new \PhpSsrs\ReportingService2010\CreateRole($attrs['NAME'], $attrs['DESCRIPTION'], []);
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
                $folder = new \PhpSsrs\ReportingService2010\CreateFolder($attrs['NAME'], $this->path->top(), []);
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
        }
    }

    protected function endElement($parser, $name)
    {
        switch ($name) {
            case 'ROLE':
                fwrite(STDOUT, 'Creating role');
                $role = $this->stack->pop();
                $response = $this->rs->CreateFolder(new \PhpSsrs\ReportingService2010\CreateFolder('hi', '/', []));
                //$response = $this->rs->CreateRole($role);
                var_dump($response);
                exit;
                break;
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
     * @return ReportingService2010
     */
    protected function getClient()
    {
        // Replace WSDL URL with your URL, or even better a locally saved version of the file.
        $rs = new ReportingService2010([
            'soap_version' => SOAP_1_2,
            'compression' => true,
            'exceptions' => true,
            //'cache_wsdl' => WSDL_CACHE_BOTH,
            'keep_alive' => true,
            //'features' => SOAP_SINGLE_ELEMENT_ARRAYS & SOAP_USE_XSI_ARRAY_TYPE,
            //'location' => 'http://hksql-t01.services.local/reportserver/ReportService2010.asmx',
            //'uri' => 'http://schemas.microsoft.com/sqlserver/reporting/2010/03/01/ReportServer',
            //'style' => SOAP_DOCUMENT,
            //'use' => SOAP_LITERAL,
            'login' => 'NAVITAS\\chris.kings-lynne',
            'password' => 'Minecraft1.7.1',
            //'proxy_host' => 'localhost',
            //'proxy_port' => 8888
        ], 'ReportService2010.wsdl');

        return $rs;
    }
}
