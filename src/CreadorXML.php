<?php
/**
 * Clase con las funciones para crear los archivos XML y firmarlos
 *  
 * PHP version 7.1
 * 
 * @category  Facturacion-electronica
 * @package   Contica\eFacturacion
 * @author    Josias Martin <josiasmc@emypeople.net>
 * @copyright 2018 Josias Martin
 * @license   https://opensource.org/licenses/MIT MIT
 * @version   GIT: <git-id>
 * @link      https://github.com/josiasmc/facturacion-electronica-cr
 */

namespace Contica\eFacturacion;
use \Defuse\Crypto\Crypto;
use \Sabre\Xml\Writer;

/**
 * Todas los metodos para crear los archivos XML
 * 
 * @category Facturacion-electronica
 * @package  Contica\eFacturacion\CreadorXML
 * @author   Josias Martin <josiasmc@emypeople.net>
 * @license  https://opensource.org/licenses/MIT MIT
 * @version  Release: <package-version>
 * @link     https://github.com/josiasmc/facturacion-electronica-cr
 */
class CreadorXML
{
    protected $publicKey;
    protected $privateKey;
    protected $modulus;
    protected $exponent;

    /**
     * Class constructor
     * 
     * @param array $container Container with settings
     */
    public function __construct($container)
    {
        $db = $container['db'];
        $id = $container['id'];
        $sql = "SELECT Certificado_mh, Pin_mh FROM Empresas WHERE Cedula=$id";
        $result = $db->query($sql);
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $pin = Crypto::decrypt($row['Pin_mh'], $container['cryptoKey']);
            openssl_pkcs12_read($row['Certificado_mh'], $key, $pin);
            $this->publicKey  = $key["cert"];
            $this->privateKey = $key["pkey"];
            $complem = openssl_pkey_get_details(
                openssl_pkey_get_private($key['pkey'])
            );
            $this->modulus = base64_encode($complem['rsa']['n']);
            $this->exponent = base64_encode($complem['rsa']['e']);
        }
    }

    /**
     * Creador de XML
     * 
     * @param array $datos Los datos del comprobante a crear
     * 
     * @return string El XML firmado
     */
    public function crearXml($datos)
    {

        // Revisar el tipo de comprobante suministrado
        $tipo = substr($datos['NumeroConsecutivo'], 8, 2);

        switch ($tipo) {
        // Factura electronica
        case '01':
            return $this->_crearFactura($datos);
            break;
        // Nota de debito electronico
        case '02':
            return $this->_crearNotaDebito($datos);
            break;
        // Nota de credito electronico
        case '03':
            return $this->_crearNotaCredito($datos);
            break;
        // Tiquete electronico
        case '04':
            return $this->_crearTiquete($datos);
            break;
        // Confirmacion de aceptacion del comprobante electronico
        case '05':
            return $this->_crearAceptacion();
            break;
        // Confirmacion de aceptacion parcial del comprobante electronico
        case '06':
            return $this->_crearAceptacionParcial();
            break;
        // Confirmacion de rechazo del comprobante electronico
        case '07':
            return $this->_crearRechazo();
            break;
        // Quien sabe que mandaron
        default:
            return false;
        }
    }

    /**
     * Creador del XML firmado para factura electronica
     * 
     * @param array $datos Los datos de la factura
     * 
     * @return string El contenido del XML firmado
     */
    private function _crearFactura($datos)
    {
        $ns = 'https://tribunet.hacienda.go.cr/'.
            'docs/esquemas/2017/v4.2/facturaElectronica';
        $xmlService = new XmlService;
        $xmlService->namespaceMap = [
            'http://www.w3.org/2001/XMLSchema' => 'xsd',
            'http://www.w3.org/2001/XMLSchema-instance' => 'xsi',
            $ns => ''
        ];
        
        $xml = $xmlService->write('{' . $ns . '}' . 'FacturaElectronica', $datos);
        return $this->_firmarXml($xml, $ns);
    }

    /**
     * Creador del XML firmado para nota de debito electronica
     * 
     * @param array $datos Los datos de la nota de debito
     * 
     * @return string El contenido del XML firmado
     */
    private function _crearNotaDebito($datos)
    {
        $ns = 'https://tribunet.hacienda.go.cr/'.
            'docs/esquemas/2017/v4.2/notaDebitoElectronica';
        $xmlService = new XmlService;
        $xmlService->namespaceMap = [
            'http://www.w3.org/2001/XMLSchema' => 'xsd',
            'http://www.w3.org/2001/XMLSchema-instance' => 'xsi',
            $ns => ''
        ];
        
        $xml = $xmlService->write('{' . $ns . '}' . 'NotaDebitoElectronica', $datos);
        return $this->_firmarXml($xml, $ns);
    }

    /**
     * Creador del XML firmado para nota de credito electronica
     * 
     * @param array $datos Los datos de la nota de credito
     * 
     * @return string El contenido del XML firmado
     */
    private function _crearNotaCredito($datos)
    {
        $ns = 'https://tribunet.hacienda.go.cr/'.
            'docs/esquemas/2017/v4.2/notaCreditoElectronica';
        $xmlService = new XmlService;
        $xmlService->namespaceMap = [
            'http://www.w3.org/2001/XMLSchema' => 'xsd',
            'http://www.w3.org/2001/XMLSchema-instance' => 'xsi',
            $ns => ''
        ];
        
        $xml = $xmlService->write('{' . $ns . '}' . 'NotaCreditoElectronica', $datos);
        return $this->_firmarXml($xml, $ns);
    }

    /**
     * Creador del XML firmado para tiquete electronico
     * 
     * @param array $datos Los datos del tiquete
     * 
     * @return string El contenido del XML firmado
     */
    private function _crearTiquete($datos)
    {
        $ns = 'https://tribunet.hacienda.go.cr/'.
            'docs/esquemas/2017/v4.2/tiqueteElectronico';
        $xmlService = new XmlService;
        $xmlService->namespaceMap = [
            'http://www.w3.org/2001/XMLSchema' => 'xsd',
            'http://www.w3.org/2001/XMLSchema-instance' => 'xsi',
            $ns => ''
        ];
        
        $xml = $xmlService->write('{' . $ns . '}' . 'TiqueteElectronico', $datos);
        return $this->_firmarXml($xml, $ns);
    }

    /**
     * Firmador de XML
     * 
     * @param string $xml El xml a firmar
     * @param string $ns  El namespace del xml
     * 
     * @return string El XML firmado
     */
    private function _firmarXml($xml, $ns)
    {
        $file = fopen(__DIR__ . "/fe.xml", "w");
        fwrite($file, $xml);
        fclose($file);
        // Definir los namespace para los diferentes nodos
        $xmlns = [
            'xmlns' => 'xmlns:ds="http://www.w3.org/2000/09/xmldsig#" '.
                'xmlns:fe="http://www.dian.gov.co/contratos/facturaelectronica/v1" '.
                'xmlns:xades="http://uri.etsi.org/01903/v1.3.2#"',
            'xmlns_keyinfo' => 
                'xmlns="'.$ns.'" ' .
                'xmlns:ds="http://www.w3.org/2000/09/xmldsig#" ' .
                'xmlns:xsd="http://www.w3.org/2001/XMLSchema" ' .
                'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"',
            'xmlns_signedprops' =>
                'xmlns="'.$ns.'" ' .
                'xmlns:ds="http://www.w3.org/2000/09/xmldsig#" ' .
                'xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" ' .
                'xmlns:xsd="http://www.w3.org/2001/XMLSchema" ' .
                'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"',
            'xmlns_signeg' =>
                'xmlns="'.$ns.'" ' .
                'xmlns:ds="http://www.w3.org/2000/09/xmldsig#" ' .
                'xmlns:xsd="http://www.w3.org/2001/XMLSchema" ' .
                'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            ];

        $xmlService = new XmlService;
        $xmlService->namespaceMap = [
            'http://www.w3.org/2000/09/xmldsig#' => 'ds',
            'http://uri.etsi.org/01903/v1.3.2#' => 'xades'
        ];
        //canoniza todo el documento y crea el digest
        $docDigest = $this->_c14DigestSHA256($xml);

        $ns_ds     = '{http://www.w3.org/2000/09/xmldsig#}';
        $ns_xades  = '{http://uri.etsi.org/01903/v1.3.2#}';
        $sha1alg   = 'http://www.w3.org/2000/09/xmldsig#sha1';
        $sha256alg = 'http://www.w3.org/2001/04/xmlenc#sha256';

        $ID               = $this->genId();
        $signatureID      = "Signature-$ID";
        $signatureValueId = "SignatureValue-$ID";
        $XadesObjectId    = "XadesObjectId-" . $this->genId();
        $KeyInfoId        = "KeyInfoId-$signatureID";
        $Reference0Id     = "Reference-" . $this->genId();
        $Reference1Id     = "ReferenceKeyInfo";
        $SignedPropertiesId = "SignedProperties-$signatureID";
        $QualifyingProps  = "QualifyingProperties-" . $this->genId();
        // Certificate digest
        $certDigest       = base64_encode(
            openssl_x509_fingerprint($this->publicKey, "sha256", true)
        );

        // Certificate issuer
        $certData   = openssl_x509_parse($this->publicKey);
        $certIssuer = array();
        foreach ($certData['issuer'] as $item => $value) {
            $certIssuer[] = $item . '=' . $value;
        }
        $certIssuer = implode(', ', array_reverse($certIssuer));

        // Certificate Serial Number
        $certSN     = $certData['serialNumber'];

        $sigPolicyId = 'https://tribunet.hacienda.go.cr/docs/esquemas/2016/v4/'.
        'Resolucion%20Comprobantes%20Electronicos%20%20DGT-R-48-2016.pdf';
        $sigPolicyHash = 'V8lVVNGDCPen6VELRD1Ja8HARFk='; //hash en sha1 y base64
        // echo {sha1sum} | xxd -r -p | base64

        date_default_timezone_set("America/Costa_Rica");
        $signTime = date('c');

        //---------------Crear los objetos a firmar----------------------
        
        //----------------------Objeto KeyInfo---------------------------
        $KeyInfo = [
            $ns_ds . 'X509Data' => [
                $ns_ds . 'X509Certificate' => $this->_cleanKey($this->publicKey)
            ],
            $ns_ds . 'KeyValue' => [
                $ns_ds . 'RSAKeyValue' => [
                    $ns_ds . 'Modulus' => $this->modulus,
                    $ns_ds . 'Exponent' => $this->exponent
                ]
            ]
        ];
        //----------------Prepare the KeyInfo Digest---------------------
        $xml_KeyInfo = $xmlService->writeFragment(
            [
                'name' => $ns_ds . 'KeyInfo',
                'attributes' => [
                    'Id' => $KeyInfoId
                ]
            ],
            $KeyInfo
        );
        $xml_KeyInfo = preg_replace('/>\s+</', '><', $xml_KeyInfo);
        $xmlDsKeyInfo = trim($xml_KeyInfo);
        $xml_KeyInfo = str_replace(
            '<ds:KeyInfo',
            '<ds:KeyInfo ' . $xmlns['xmlns_keyinfo'],
            $xml_KeyInfo
        );
        $keyDigest = $this->_c14DigestSHA256(trim($xml_KeyInfo));

        //-------SignedProperties node--------
        $SignedProperties = [
            $ns_xades . 'SignedSignatureProperties' => [
                $ns_xades . 'SigningTime' => $signTime,
                $ns_xades . 'SigningCertificate' => [
                    $ns_xades . 'Cert' => [
                        $ns_xades . 'CertDigest' => [
                            [
                                'name' => $ns_ds . 'DigestMethod',  
                                'attributes' => [
                                    'Algorithm' => $sha256alg
                                ]
                            ],
                            $ns_ds . 'DigestValue' => $certDigest//certDigest
                        ],
                        $ns_xades . 'IssuerSerial' => [
                            $ns_ds . 'X509IssuerName' => $certIssuer,//certIssuer
                            $ns_ds . 'X509SerialNumber' => $certSN//certSN
                        ]
                    ]
                ],
                $ns_xades . 'SignaturePolicyIdentifier' => [
                    $ns_xades . 'SignaturePolicyId' => [
                        $ns_xades . 'SigPolicyId' => [
                            $ns_xades . 'Identifier' => $sigPolicyId,//sigPolicyId
                            [
                                'name' => $ns_xades . 'Description',
                                'attributes' => []
                            ]
                        ],
                        $ns_xades . 'SigPolicyHash' => [
                            [
                                'name' => $ns_ds . 'DigestMethod',
                                'attributes' => [
                                    'Algorithm' => $sha1alg]],
                            $ns_ds . 'DigestValue' => $sigPolicyHash//sigPolicyHash
                        ]
                    ]
                ],
            ],
            $ns_xades . 'SignedDataObjectProperties' => [
                [
                    'name' => $ns_xades . 'DataObjectFormat',
                    'attributes' => [
                        'ObjectReference' => '#' . $Reference0Id
                    ],
                    'value' => [
                        $ns_xades . 'MimeType' => 'text/xml',
                        $ns_xades . 'Encoding' => 'UTF-8'
                    ]
                ]
            ]
            
        ];
        $xml_SignedProperties = $xmlService->writeFragment(
            [            
                'name' => $ns_xades . 'SignedProperties',
                'attributes' => [
                    'Id' => $SignedPropertiesId,
                ]
            ],
            $SignedProperties
        );
        $xml_SignedProperties = preg_replace('/>\s+</', '><', $xml_SignedProperties);
        $xmlDsSignedProperties = trim($xml_SignedProperties);
        $xml_SignedProperties = str_replace(
            '<xades:SignedProperties',
            '<xades:SignedProperties ' . $xmlns['xmlns_signedprops'],
            $xml_SignedProperties
        );
        $propDigest = $this->_c14DigestSHA256($xml_SignedProperties);

        $SignedInfo = [
            [
            'name' => $ns_ds . 'CanonicalizationMethod',
            'attributes' => [
                'Algorithm' => 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315'
                ]
            ],
            [
            'name' => $ns_ds . 'SignatureMethod',
            'attributes' => [
                'Algorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256'
                ]
            ],
            // Reference for the XML document
            [
                'name' => $ns_ds . 'Reference',
                'attributes' => [
                    'Id' => $Reference0Id,
                    'URI' => ''
                ],
                'value' => [
                    $ns_ds . 'Transforms' => [
                        'name' => $ns_ds . 'Transform',
                        'attributes' => [
                            'Algorithm' => 
                            'http://www.w3.org/2000/09/xmldsig#enveloped-signature'
                        ]
                    ],
                    [
                        'name' => $ns_ds . 'DigestMethod',
                        'attributes' => [
                            'Algorithm' => $sha256alg
                        ]
                    ],
                    $ns_ds . 'DigestValue' => $docDigest
                ]
            ],
            // Reference for KeyInfo object
            [
                'name' => $ns_ds . 'Reference',
                'attributes' => [
                    'Id' => $Reference1Id,
                    'URI' => '#'.$KeyInfoId
                ],
                'value' => [
                    [
                        'name' => $ns_ds . 'DigestMethod',
                        'attributes' => [
                            'Algorithm' => $sha256alg
                        ]
                    ],
                    $ns_ds . 'DigestValue' => $keyDigest
                ]
            ],
            // Reference for SignedProperties object
            [
                'name' => $ns_ds . 'Reference',
                'attributes' => [
                    'Type' => 'http://uri.etsi.org/01903#SignedProperties',
                    'URI' => "#$SignedPropertiesId"
                ],
                'value' => [
                    [
                        'name' => $ns_ds . 'DigestMethod',
                        'attributes' => [
                            'Algorithm' => $sha256alg
                        ]
                    ],
                    $ns_ds . 'DigestValue' => $propDigest
                ]
            ]
        ];
        // El elemento SignedInfo es el que se firma criptograficamente
        $xml_SignedInfo = $xmlService->writeFragment(
            $ns_ds . 'SignedInfo',
            $SignedInfo
        );
        $xml_SignedInfo = preg_replace('/>\s+</', '><', $xml_SignedInfo);
        $xmlDsSignedInfo = trim($xml_SignedInfo);
        $xml_SignedInfo = str_replace(
            '<ds:SignedInfo',
            '<ds:SignedInfo ' . $xmlns['xmlns_signeg'],
            $xml_SignedInfo
        );

        $d1p = new \DOMDocument('1.0', 'UTF-8');
        $d1p->loadXML($xml_SignedInfo);
        $xml_SignedInfo = $d1p->C14N();

        // La firma es el resultado de firmar el nodo SignedInfo
        $signatureResult = "";

        openssl_sign(
            $xml_SignedInfo,
            $signatureResult,
            $this->privateKey,
            'SHA256'
        );

        $signatureResult = base64_encode($signatureResult);
        $SignatureValue = $signatureResult;//Signature value
        
        $Object = [
            [
                'name' => $ns_xades. 'QualifyingProperties',
                'attributes' => [
                    'Id' => $QualifyingProps,
                    'Target' => "#$signatureID"
                ],
                'value' => [
                    'name' => $ns_xades . 'SignedProperties',
                    'value' => ''
                ]
            ]
        ];
        $firma = [
            [
                'name' => $ns_ds . 'SignedInfo',
                'value' => ''
            ],
            [
                'name' => $ns_ds . 'SignatureValue',
                'attributes' => [
                    'Id' => $signatureValueId
                ],
                'value' => $SignatureValue
            ],
            [
                'name' => $ns_ds . 'KeyInfo',
                'value' => ''
            ],
            [
                'name' => $ns_ds . 'Object',
                'attributes' => [
                    'Id' => $XadesObjectId
                ],
                'value' => $Object
            ]
        ];
        
        $xml_firma =  $xmlService->writeSignature(
            [
                'name' => $ns_ds . 'Signature',
                'attributes' => [
                    'Id' => $signatureID
                ],
            ],
            $firma
        );
        $xml_firma = str_replace(
            '<ds:KeyInfo></ds:KeyInfo>',
            $xmlDsKeyInfo,
            $xml_firma
        );
        $xml_firma = str_replace(
            '<xades:SignedProperties></xades:SignedProperties>',
            $xmlDsSignedProperties,
            $xml_firma
        );
        $xml_firma = str_replace(
            '<ds:SignedInfo></ds:SignedInfo>',
            $xmlDsSignedInfo,
            $xml_firma
        );
        $xml_firma = preg_replace('/>\s+</', '><', $xml_firma);
        // Insertar la firma en el documento xml
        $rPos = strripos($xml, '</', strlen($xml)-30);
        $rStr = substr($xml, $rPos);
        return str_replace($rStr, rtrim($xml_firma) . $rStr, $xml);

    }/*
    public function getCert()
    {
        return $this->cert;
    }
    */

    /**
     * C14N SHA256 digest de una cadena
     * 
     * @param string $xml El texto para procesar
     * 
     * @return string El Digest
     */
    private function _c14DigestSHA256($xml) 
    {
        $d = new \DOMDocument('1.0', 'UTF-8');
        $d->loadXML($xml);
        return base64_encode(hash('sha256', $d->C14N(), true));
    }

    /**
     * Limpiador de llave criptografica
     * 
     * @param string $key La llave
     * 
     * @return string La llave limpiada
     */
    private function _cleanKey($key) 
    {
        return trim(
            str_replace(
                [
                    '-----BEGIN CERTIFICATE-----',
                    '-----END CERTIFICATE-----',
                    '-----BEGIN PRIVATE KEY-----',
                    '-----END PRIVATE KEY-----',
                    "\r",
                    "\n"
                ],
                '',
                $key
            )
        );
    }

    /**
     * Generador de ids
     * 
     * @return string el id generado
     */
    function genId()
    {
        $id = [
            date('ym'),
            date('d'),
            date('H'),
            date('i'),
            date('U')
        ];
        $ans = "";
        $rand = rand(127, 512);
        foreach ($id as $value) {
            $ans .= '-' . base_convert($value * $rand, 10, 16);
        }
        return substr($ans, 1);
    }
}