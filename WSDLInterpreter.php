<?php

/**
 * IMPORTANT
 * 
 * This class was edited by Markus Staab and is not longer in its origin state.
 * Therefore do not override with a possible later version of the underlying class!
 */

/**
 * Interprets WSDL documents for the purposes of PHP 5 object creation
 * 
 * The WSDLInterpreter package is used for the interpretation of a WSDL 
 * document into PHP classes that represent the messages using inheritance
 * and typing as defined by the WSDL rather than SoapClient's limited
 * interpretation.  PHP classes are also created for each service that
 * represent the methods with any appropriate overloading and strict
 * variable type checking as defined by the WSDL.
 *
 * PHP version 5 
 * 
 * LICENSE: This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category    WebServices 
 * @package     WSDLInterpreter  
 * @author      Kevin Vaughan kevin@kevinvaughan.com
 * @copyright   2007 Kevin Vaughan
 * @license     http://www.gnu.org/copyleft/lesser.html  LGPL License 2.1
 */

/**
 * A lightweight wrapper of Exception to provide basic package specific 
 * unrecoverable program states.
 * 
 * @category WebServices
 * @package WSDLInterpreter
 */
class WSDLInterpreterException extends Exception { } 

/**
 * The main class for handling WSDL interpretation
 * 
 * The WSDLInterpreter is utilized for the parsing of a WSDL document for rapid
 * and flexible use within the context of PHP 5 scripts.
 * 
 * Example Usage:
 * <code>
 * require_once 'WSDLInterpreter.php';
 * $myWSDLlocation = 'http://www.example.com/ExampleService?wsdl';
 * $wsdlInterpreter = new WSDLInterpreter($myWSDLlocation);
 * $wsdlInterpreter->savePHP('/example/output/directory/');
 * </code>
 * 
 * @category WebServices
 * @package WSDLInterpreter
 */
class WSDLInterpreter 
{
    /**
     * The WSDL document's URI
     * @var string
     * @access private
     */
    private $_wsdl = null;

    /**
     * A SoapClient for loading the WSDL
     * @var \SoapClient
     * @access private
     */
    private $_client = null;
    
    /**
     * DOM document representation of the wsdl and its translation
     * @var DOMDocument
     * @access private
     */
    private $_dom = null;
    
    /**
     * Array of classes and members representing the WSDL message types
     * @var array
     * @access private
     */
    private $_classmap = array();
    
    /**
     * Array of sources for WSDL message classes
     * @var array
     * @access private
     */
    private $_classPHPSources = array();
    
    /**
     * Array of sources for WSDL services
     * @var array
     * @access private
     */
    private $_servicePHPSources = array();
    
    /**
     * Array of aliases for the services
     * @var array
     * @access private
     */     
    private $_serviceNameAliases = array();
    
    /**
     * Parses the target wsdl and loads the interpretation into object members
     * 
     * @param string $wsdl  the URI of the wsdl to interpret
     * @throws WSDLInterpreterException Container for all WSDL interpretation problems
     * @todo Create plug in model to handle extendability of WSDL files
     */
    public function __construct($wsdl)
    {
        try {
            $this->_wsdl = $wsdl;
	    $options = array();
            $this->_client = new SoapClient($wsdl, $options);
            
            $this->_dom = new DOMDocument();
	    // JB: Bei Fehlern diese Variante ohne LIBXML_DTDLOAD ausprobieren
	    // $this->_dom->load($wsdl, LIBXML_DTDATTR|LIBXML_NOENT|LIBXML_XINCLUDE);
	    $this->_dom->load($wsdl, LIBXML_DTDLOAD|LIBXML_DTDATTR|LIBXML_NOENT|LIBXML_XINCLUDE);
            
            $xpath = new DOMXPath($this->_dom);
            
            /**
             * wsdl:import
             */
            $query = "//*[local-name()='import' and namespace-uri()='http://schemas.xmlsoap.org/wsdl/']";
            $entries = $xpath->query($query);
            foreach ($entries as $entry) {
                $parent = $entry->parentNode;
                $wsdl = new DOMDocument();
                $wsdl->load($entry->getAttribute("location"), LIBXML_DTDLOAD|LIBXML_DTDATTR|LIBXML_NOENT|LIBXML_XINCLUDE);
                foreach ($wsdl->documentElement->childNodes as $node) {
                    $newNode = $this->_dom->importNode($node, true);
                    $parent->insertBefore($newNode, $entry);
                }
                $parent->removeChild($entry);
            }
            
            /**
             * xsd:import
             */

            /**
             * RR
             * Dieser Block macht gerne Probleme
             * problem methode in die ein byte[] (Byte-Array) als Parameter erwartet hat
             */
            $query = "//*[local-name()='import' and namespace-uri()='http://www.w3.org/2001/XMLSchema']";
            $entries = $xpath->query($query);
            foreach ($entries as $entry) {
                $parent = $entry->parentNode;
                $xsd = new DOMDocument();
                $result = @$xsd->load(dirname($this->_wsdl) . "/" . $entry->getAttribute("schemaLocation"), 
                    LIBXML_DTDLOAD|LIBXML_DTDATTR|LIBXML_NOENT|LIBXML_XINCLUDE);
                if ($result) {
                    foreach ($xsd->documentElement->childNodes as $node) {
                        $newNode = $this->_dom->importNode($node, true);
                        $parent->insertBefore($newNode, $entry);
                    }
                    $parent->removeChild($entry);
                }
	    }
            
            
            $this->_dom->formatOutput = true;
        } catch (Exception $e) {
            throw new WSDLInterpreterException("Error loading WSDL document (".$e->getMessage().")");
        }
        
        try {
            $xsl = new XSLTProcessor();
            $xslDom = new DOMDocument();
            $xslDom->load(dirname(__FILE__)."/wsdl2php.xsl");
            $xsl->registerPHPFunctions();
            $xsl->importStyleSheet($xslDom);
            $this->_dom = $xsl->transformToDoc($this->_dom);
            $this->_dom->formatOutput = true;
        } catch (Exception $e) {
            throw new WSDLInterpreterException("Error interpreting WSDL document (".$e->getMessage().")");
        }
       
        // TODO extract initialization of the following methodes into a public method, so we can respect serviceAliases  
        $this->_loadClasses();
        $this->_loadServices();
    }

    /**
     * Validates a name against standard PHP naming conventions
     * 
     * @param string $name the name to validate
     * 
     * @return string the validated version of the submitted name
     * 
     * @access private
     */
    private function _validateNamingConvention($name) 
    {
        return preg_replace('#[^a-zA-Z0-9_\x7f-\xff]*#', '',
            preg_replace('#^[^a-zA-Z_\x7f-\xff]*#', '', $name));
    }
    
    /**
     * Validates a class name against PHP naming conventions and already defined
     * classes, and optionally stores the class as a member of the interpreted classmap.
     * 
     * @param string $className the name of the class to test
     * @param boolean $addToClassMap whether to add this class name to the classmap
     * 
     * @return string the validated version of the submitted class name
     * 
     * @access private
     * @todo Add reserved keyword checks
     */
    private function _validateClassName($className, $addToClassMap = true) 
    {
        $validClassName = $this->_validateNamingConvention($className);

        /*
        if (class_exists($validClassName)) {
            throw new Exception("Class ".$validClassName." already defined.".
                " Cannot redefine class with class loaded.");
        }
        */
        
        if ($addToClassMap) {
            $this->_classmap[$className] = $validClassName;
        }
        
        return $validClassName;
    }

    
    /**
     * Validates a wsdl type against known PHP primitive types, or otherwise
     * validates the namespace of the type to PHP naming conventions
     * 
     * @param string $type the type to test
     * 
     * @return string the validated version of the submitted type
     * 
     * @access private
     * @todo Extend type handling to gracefully manage extendability of wsdl definitions, add reserved keyword checking
     */    
    private function _validateType($type) 
    {
        // STM: check for namespaces
        $nsParts = array();
        if(strpos($type, ':') !== false)
        {
            $nsParts = explode(':', $type); // WSDL usese ":"-separator for packages
            $type = array_pop($nsParts);
        }
        // /STM
        $array = false;
        if (substr($type, -2) == "[]") {
            $array = true;
            $type = substr($type, 0, -2);
        }
        switch (strtolower($type)) {
        case "int": case "integer": case "long": case "byte": case "short":
        case "negativeInteger": case "nonNegativeInteger": 
        case "nonPositiveInteger": case "positiveInteger":
        case "unsignedByte": case "unsignedInt": case "unsignedLong": case "unsignedShort":
            $validType = "integer";
            break;
            
        case "float": case "long": case "double": case "decimal":
            $validType = "double";
            break;
            
        case "string": case "token": case "normalizedString": case "hexBinary":
            $validType = "string";
            break;
            
        default:
            $validType = $this->_validateNamingConvention($type);
            // STM: re-add namespace
            $nsParts[] = $validType;
            $validType = implode($nsParts, '_');
            // / STM
            break;
        }
        if ($array) {
            $validType .= "[]";
        }
        return $validType;
    }

    private function isPhpPrimitive($type)
    {
        if(!is_string($type))
        {
            throw new Exception('Given type must be a string, '. gettype($type) .' given!');
        }

        $phpTypes = array('integer', 'double', 'string');
        return in_array($type, $phpTypes);
    }
    
    /**
     * Loads classes from the translated wsdl document's message types 
     * 
     * @access private
     */
    private function _loadClasses() 
    {
        $classes = $this->_dom->getElementsByTagName("class");
        foreach ($classes as $class) {
            $class->setAttribute("validatedName", 
                $this->_validateClassName($class->getAttribute("name")));
            $extends = $class->getElementsByTagName("extends");
            if ($extends->length > 0) {
                $extends->item(0)->nodeValue = 
                    $this->_validateClassName($extends->item(0)->nodeValue);
                $classExtension = $extends->item(0)->nodeValue;
            } else {
                $classExtension = false;
            }
            $properties = $class->getElementsByTagName("entry");
            foreach ($properties as $property) {
                $property->setAttribute("validatedName", 
                    $this->_validateNamingConvention($property->getAttribute("name")));
                $property->setAttribute("type", 
                    $this->_validateType($property->getAttribute("type")));
            }
            
            $sources[$class->getAttribute("validatedName")] = array(
                "extends" => $classExtension,
                "source" => $this->_generateClassPHP($class)
            );
        }
        
        while (sizeof($sources) > 0)
        {
            $classesLoaded = 0;
            foreach ($sources as $className => $classInfo) {
                if (!$classInfo["extends"] || (isset($this->_classPHPSources[$classInfo["extends"]]))) {
                    $this->_classPHPSources[$className] = $classInfo["source"];
                    unset($sources[$className]);
                    $classesLoaded++;
                }
            }
            if (($classesLoaded == 0) && (sizeof($sources) > 0)) {
                throw new WSDLInterpreterException("Error loading PHP classes: ".join(", ", array_keys($sources)));
            }
        }
    }
    
    /**
     * Generates the PHP code for a WSDL message type class representation
     * 
     * This gets a little bit fancy as the magic methods __get and __set in
     * the generated classes are used for properties that are not named 
     * according to PHP naming conventions (e.g., "MY-VARIABLE").  These
     * variables are set directly by SoapClient within the target class,
     * and could normally be retrieved by $myClass->{"MY-VARIABLE"}.  For
     * convenience, however, this will be available as $myClass->MYVARIABLE.
     * 
     * @param DOMElement $class the interpreted WSDL message type node
     * @return string the php source code for the message type class
     * 
     * @access private
     * @todo Include any applicable annotation from WSDL
     */
    private function _generateClassPHP($class) 
    {
        $return = "";
        // $return .= 'if (!class_exists("'.$class->getAttribute("validatedName").'")) {'."\n";
        $return .= '/**'."\n";
        $return .= ' * '.$class->getAttribute("validatedName")."\n";
        $return .= ' */'."\n";
        $return .= "class ".$class->getAttribute("validatedName");
        $extends = $class->getElementsByTagName("extends");
        if ($extends->length > 0) {
            $return .= " extends ".$extends->item(0)->nodeValue;
        }
        $return .= " {\n";
    
        $properties = $class->getElementsByTagName("entry");
        foreach ($properties as $property) {
            $return .= "\t/**\n"
                     . "\t * @access public\n"
                     . "\t * @var ".$property->getAttribute("type")."\n"
                     . "\t */\n"
                     . "\t".'public $'.$property->getAttribute("validatedName").";\n";
        }
    
        $extraParams = false;
        $paramMapReturn = "\t".'private $_parameterMap = array ('."\n";
        $properties = $class->getElementsByTagName("entry");
        foreach ($properties as $property) {
            if ($property->getAttribute("name") != $property->getAttribute("validatedName")) {
                $extraParams = true;
                $paramMapReturn .= "\t\t".'"'.$property->getAttribute("name").
                    '" => "'.$property->getAttribute("validatedName").'",'."\n";
            }
        }
        $paramMapReturn .= "\t".');'."\n";
        $paramMapReturn .= "\t".'/**'."\n";
        $paramMapReturn .= "\t".' * Provided for setting non-php-standard named variables'."\n";
        $paramMapReturn .= "\t".' * @param $var Variable name to set'."\n";
        $paramMapReturn .= "\t".' * @param $value Value to set'."\n";
        $paramMapReturn .= "\t".' */'."\n";
        $paramMapReturn .= "\t".'public function __set($var, $value) '.
            '{ $this->{$this->_parameterMap[$var]} = $value; }'."\n";
        $paramMapReturn .= "\t".'/**'."\n";
        $paramMapReturn .= "\t".' * Provided for getting non-php-standard named variables'."\n";
        $paramMapReturn .= "\t".' * @param $var Variable name to get'."\n";
        $paramMapReturn .= "\t".' * @return mixed Variable value'."\n";
        $paramMapReturn .= "\t".' */'."\n";
        $paramMapReturn .= "\t".'public function __get($var) '.
            '{ return $this->{$this->_parameterMap[$var]}; }'."\n";
        
        if ($extraParams) {
            $return .= $paramMapReturn;
        }
    
        // $return .= "}}";
        $return .= "}";
        return $return;
    }
    
    /**
     * Loads services from the translated wsdl document
     * 
     * @access private
     */
    private function _loadServices() 
    {
        $services = $this->_dom->getElementsByTagName("service");
        foreach ($services as $service) {
            $service->setAttribute("validatedName", 
                $this->_validateClassName($service->getAttribute("name"), false));
            $functions = $service->getElementsByTagName("function");
            foreach ($functions as $function) {
                $function->setAttribute("validatedName", 
                    $this->_validateNamingConvention($function->getAttribute("name")));
                $parameters = $function->getElementsByTagName("parameters");
                if ($parameters->length > 0) {
                    $parameterList = $parameters->item(0)->getElementsByTagName("entry");
                    foreach ($parameterList as $variable) {
                        $variable->setAttribute("validatedName", 
                            $this->_validateNamingConvention($variable->getAttribute("name")));
                        $variable->setAttribute("type", 
                            $this->_validateType($variable->getAttribute("type")));
                    }
                }
            }
            
            $this->_servicePHPSources[$service->getAttribute("validatedName")] = 
                $this->_generateServicePHP($service);
        }
    }
    
    /**
     * Generates the PHP code for a WSDL service class representation
     * 
     * This method, in combination with generateServiceFunctionPHP, create a PHP class
     * representation capable of handling overloaded methods with strict parameter
     * type checking.
     * 
     * @param DOMElement $service the interpreted WSDL service node
     * @return string the php source code for the service class
     * 
     * @access private
     * @todo Include any applicable annotation from WSDL
     */
    private function _generateServicePHP($service) 
    {
        $return = "";
        // $return .= 'if (!class_exists("'.$service->getAttribute("validatedName").'")) {'."\n";
        $return .= '/**'."\n";
        $return .= ' * '.$service->getAttribute("validatedName")."\n";
        $return .= ' * @author WSDLInterpreter'."\n";
        $return .= ' */'."\n";
        // $return .= "class ".$service->getAttribute("validatedName")." extends SoapClient {\n";
        $return .= "class ".$service->getAttribute("validatedName")." {\n";

        if (sizeof($this->_classmap) > 0) {
            $return .= "\t".'/**'."\n";
            $return .= "\t".' * Default class map for wsdl=>php'."\n";
            $return .= "\t".' * @access private'."\n";
            $return .= "\t".' * @var array'."\n";
            $return .= "\t".' */'."\n";
            $return .= "\t".'private static $classmap = array('."\n";
            foreach ($this->_classmap as $className => $validClassName)    {
                if($this->isPhpPrimitive($validClassName))
                {
                    $return .= "\t\t".'"'.$className.'" => "'.$validClassName.'",'."\n";
                }
                else
                {
                    $return .= "\t\t".'"'.$className.'" => "'.$service->getAttribute("validatedName") .'\\'. $validClassName.'",'."\n";
                }
            }
            $return .= "\t);\n\n";
        }

        // STM:use SoapClient as class member
        $return .= "\t".'/**'."\n";
        $return .= "\t".' * Ws handle'."\n";
        $return .= "\t".' * @access private'."\n";
        $return .= "\t".' * @var \SoapClient'."\n";
        $return .= "\t".' */'."\n";
        $return .= "\t".'private $wsHandle;'."\n\n";
        // /STM
        
        $return .= "\t".'/**'."\n";
        $return .= "\t".' * Constructor using wsdl location and options array'."\n";
        $return .= "\t".' * @param string $wsdl WSDL location for this service'."\n";
        $return .= "\t".' * @param array $options Options for the SoapClient'."\n";
        $return .= "\t".' */'."\n";
        $return .= "\t".'public function __construct($wsdl="'.
            $this->_wsdl.'", $options=array()) {'."\n";
        $return .= "\t\t".'foreach(self::$classmap as $wsdlClassName => $phpClassName) {'."\n";
        $return .= "\t\t".'    if(!isset($options[\'classmap\'][$wsdlClassName])) {'."\n";
        $return .= "\t\t".'        $options[\'classmap\'][$wsdlClassName] = $phpClassName;'."\n";
        $return .= "\t\t".'    }'."\n";
        $return .= "\t\t".'}'."\n";
        // STM: use SoapClient as a class member
        // $return .= "\t\t".'parent::__construct($wsdl, $options);'."\n";
        $return .= "\t\t".'$this->wsHandle = new \SoapClient($wsdl, $options);'."\n";
        // /STM
        $return .= "\t}\n\n";

//        $return .= "\t".'/**'."\n";
//        $return .= "\t".' * Checks if an argument list matches against a valid '.
//            'argument type list'."\n";
//        $return .= "\t".' * @param array $arguments The argument list to check'."\n";
//        $return .= "\t".' * @param array $validParameters A list of valid argument '.
//            'types'."\n";
//        $return .= "\t".' * @return boolean true if arguments match against '.
//            'validParameters'."\n";
//        $return .= "\t".' * @throws \Exception invalid function signature message'."\n";
//        $return .= "\t".' */'."\n";
//        // $return .= "\t".'public function _checkArguments($arguments, $validParameters) {'."\n";
//        $return .= "\t".'protected function _checkArguments($arguments, $validParameters) {'."\n";
//        $return .= "\t\t".'$variables = "";'."\n";
//        $return .= "\t\t".'foreach ($arguments as $arg) {'."\n";
//        $return .= "\t\t".'    $type = gettype($arg);'."\n";
//        $return .= "\t\t".'    if ($type == "object") {'."\n";
//        $return .= "\t\t".'        $type = get_class($arg);'."\n";
//        $return .= "\t\t".'    }'."\n";
//        $return .= "\t\t".'    $variables .= "(".$type.")";'."\n";
//        $return .= "\t\t".'}'."\n";
//        $return .= "\t\t".'if (!in_array($variables, $validParameters)) {'."\n";
//        $return .= "\t\t".'    throw new \Exception("Invalid parameter types: '.
//            '".str_replace(")(", ", ", $variables));'."\n";
//        $return .= "\t\t".'}'."\n";
//        $return .= "\t\t".'return true;'."\n";
//        $return .= "\t}\n\n";


        $functionMap = array();        
        $functions = $service->getElementsByTagName("function");
        foreach ($functions as $function) {
            if (!isset($functionMap[$function->getAttribute("validatedName")])) {
                $functionMap[$function->getAttribute("validatedName")] = array();
            }
            $functionMap[$function->getAttribute("validatedName")][] = $function;
        }    
        foreach ($functionMap as $functionName => $functionNodeList) {
            $return .= $this->_generateServiceFunctionPHP($functionName, $functionNodeList)."\n\n";
        }
    
        // $return .= "}}";
        $return .= "}";
        return $return;
    }

    /**
     * Generates the PHP code for a WSDL service operation function representation
     * 
     * The function code that is generated examines the arguments that are passed and
     * performs strict type checking against valid argument combinations for the given
     * function name, to allow for overloading.
     * 
     * @param string $functionName the php function name
     * @param array $functionNodeList array of DOMElement interpreted WSDL function nodes
     * @return string the php source code for the function
     * 
     * @access private
     * @todo Include any applicable annotation from WSDL
     */    
    private function _generateServiceFunctionPHP($functionName, $functionNodeList) 
    {
        $return = "";
        $return .= "\t".'/**'."\n";
        $return .= "\t".' * Service Call: '.$functionName."\n";
        $parameterComments = array();
        $variableTypeOptions = array();
        $returnOptions = array();
        foreach ($functionNodeList as $functionNode) {
//            $parameters = $functionNode->getElementsByTagName("parameters");
//            if ($parameters->length > 0) {
//                $parameters = $parameters->item(0)->getElementsByTagName("entry");
//                $parameterTypes = "";
//                $parameterList = array();
//                foreach ($parameters as $parameter) {
//                    if (substr($parameter->getAttribute("type"), 0, -2) == "[]") {
//                        $parameterTypes .= "(array)";
//                    } else {
//                        $parameterTypes .= "(".$parameter->getAttribute("type").")";
//                    }
//                    $parameterList[] = "(".$parameter->getAttribute("type").") ".
//                        $parameter->getAttribute("validatedName");
//                }
//                if (sizeof($parameterList) > 0) {
//                    $variableTypeOptions[] = $parameterTypes;
//                    $parameterComments[] = "\t".' * '.join(", ", $parameterList);
//                }
//            }
            $returns = $functionNode->getElementsByTagName("returns");
            if ($returns->length > 0) {
                $returns = $returns->item(0)->getElementsByTagName("entry");
                if ($returns->length > 0) {
                    $returnOptions[] = $returns->item(0)->getAttribute("type");
                }
            }
        }
        // $return .= "\t".' * Parameter options:'."\n";
        // $return .= join("\n", $parameterComments)."\n";
        // $return .= "\t".' * @param mixed,... See function description for parameter options'."\n";
        $return .= "\t".' * @param '.$functionName.' $'.$functionName."\n";
        $return .= "\t".' * @return '.join("|", array_unique($returnOptions))."\n";
        $return .= "\t".' * @throws \Exception invalid function signature message'."\n";
        $return .= "\t".' */'."\n";
        // $return .= "\t".'public function '.$functionName.'($mixed = null) {'."\n";
        $return .= "\t".'public function '.$functionName.'('.$functionName.' $'.$functionName.') {'."\n";
//        $return .= "\t\t".'$validParameters = array('."\n";
//        foreach ($variableTypeOptions as $variableTypeOption) {
//            $return .= "\t\t\t".'"'.$variableTypeOption.'",'."\n";
//        }
//        $return .= "\t\t".');'."\n";
//        $return .= "\t\t".'$args = func_get_args();'."\n";
//        $return .= "\t\t".'$this->_checkArguments($args, $validParameters);'."\n";
//        $return .= "\t\t".'return $this->__soapCall("'.
//            $functionNodeList[0]->getAttribute("name").'", $args);'."\n";
        $return .= "\t\t".'return $this->wsHandle->__soapCall("'.
            $functionNodeList[0]->getAttribute("name").'", array($'.$functionName.'));'."\n";
        $return .= "\t".'}'."\n";
        
        return $return;
    }
    
	/**
     * Set an alias for a given ServiceName. The alias will replace the ServiceNames which will be provided from the WSDL.
     */
    public function setServiceAlias($serviceName, $serviceAlias)
    {
      $this->_serviceNameAliases[$serviceName] = $serviceAlias;
    }
    
    /**
     * Returns the corresponding alias to the given ServiceName.
     * If no alias is defined, the actual ServiceName is returned.
     * 
     * @param string $serviceName
     */
    private function getServiceAlias($serviceName)
    {
        if(isset($this->_serviceNameAliases[$serviceName]))
        {
            return $this->_serviceNameAliases[$serviceName];
        }
        return $serviceName;
    }

    /**
     * Saves the PHP source code that has been loaded to a target directory.
     * 
     * Services will be saved by their validated name, and classes will be included
     * with each service file so that they can be utilized independently.
     * 
     * @param string $outputDirectory the destination directory for the source code
     * @return array array of source code files that were written out
     * @throws WSDLInterpreterException problem in writing out service sources
     * @access public
     * @todo Add split file options for more efficient output
     */
    public function savePHP($outputDirectory) 
    {
        if (sizeof($this->_servicePHPSources) == 0) {
            throw new WSDLInterpreterException("No services loaded");
        }
        $classSource = join("\n\n", $this->_classPHPSources);

        // STM
        $classSource = '
/**'. sprintf('%\'*74s', '') .'**/
/* '. sprintf('%-74s', 'This class was generated using WSDLInterpreter!') .' */
/* '. sprintf('%-74s', 'Do not modify this file manually!') .' */
/* '. sprintf('%-74s', 'Generated on '. date('r')) .' */
/* '. sprintf('%-74s', 'WSDL '. $this->_wsdl) .' */
/**'. sprintf('%\'*74s', '') .'**/

' . $classSource;
        // /STM

        $outputFiles = array();
        foreach ($this->_servicePHPSources as $serviceName => $serviceCode) {
            $serviceName = $this->getServiceAlias($serviceName);
            
            // STM - Added PHP5.3 namespace, Added Base-Classes for custom overwrites
            //$filename = $outputDirectory."/".$serviceName.".php";
            //if (file_put_contents($filename,
            //        "<?php\n\n".$classSource."\n\n".$serviceCode)) {
            //    $outputFiles[] = $filename;
            //}
            $basefile = $outputDirectory."/generated/".$serviceName."Base.php";
            $basedir = dirname($basefile);
            if(!is_dir($basedir))
            {
                if(!mkdir($basedir))
                {
                    throw new WSDLInterpreterException("Unable to create dir ". $basedir ."!");
                }
            }
            
            $serviceCode = str_replace('class '. $serviceName, 'abstract class '. $serviceName .'Base', $serviceCode); // adjust class definition to be abstract
            if (file_put_contents($basefile,
                    "<?php\n\nnamespace ".$serviceName.";\n\n".$classSource."\n\n".$serviceCode)) {
                $outputFiles[] = $basefile;
            }
            // add a servicefile only if not already exists
            $servicefile = $outputDirectory."/".$serviceName.".php";
            if(!file_exists($servicefile))
            {
                $head = '
require \'generated/'.$serviceName.'Base.php\';

/**
 * Implementation class of '.$serviceName.'.
 *
 * You may modify this class for custom logic additions/methode overrides.
 * If you do so, don\'t forget to call the overriden super-class method!
 *
 * This stub was generated using WSDLInterpreter on '. date('r') .'
 *
 * @see '.$serviceName.'Base
 */

';
                if (file_put_contents($servicefile,
                        "<?php\n\nnamespace ".$serviceName.";\n\n".$head."class ".$serviceName." extends ".$serviceName."Base\n{\n}")) {
                    $outputFiles[] = $servicefile;
                }
            }
            // /STM - Add PHP5.3 namespace
        }
        if (sizeof($outputFiles) == 0) {
            throw new WSDLInterpreterException("Error writing PHP source files.");
        }
        return $outputFiles;
    }

    // STM new interpreter methods
    /**
     * Checks wheter the webservice client already exists in the target directory.
     *
     * @param string $outputDirectory the destination directory for the source code
     * @return boolean True when the client already exists, otherwise False
     * @throws WSDLInterpreterException When wsdl was not found
     * @access public
     */
    public function clientExists($outputDirectory)
    {
        if (sizeof($this->_servicePHPSources) == 0) {
            throw new WSDLInterpreterException("No services loaded");
        }

        $outputFiles = array();
        foreach ($this->_servicePHPSources as $serviceName => $serviceCode) {
        	$serviceName = $this->getServiceAlias($serviceName);
        	
            $filename = $outputDirectory."/".$serviceName.".php";
            if(file_exists($filename)) return true;
        }
        return false;
    }
    // /STM
}

?>
