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
            return $this->_crearNotaDebito();
            break;
        // Nota de credito electronico
        case '03':
            return $this->_crearNotaCredito();
            break;
        // Tiquete electronico
        case '04':
            return $this->_crearTiquete();
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

        // Definir los namespace para los diferentes nodos
        $xmlns = [
        'xmlns' => 'xmlns:ds="http://www.w3.org/2000/09/xmldsig#" '.
            'xmlns:fe="http://www.dian.gov.co/contratos/facturaelectronica/v1" '.
            'xmlns:xades="http://uri.etsi.org/01903/v1.3.2#"',
        'xmlns_keyinfo' => 
            'xmlns="https://tribunet.hacienda.go.cr/docs/esquemas/2017/v4.2/facturaElectronica" ' .
            'xmlns:ds="http://www.w3.org/2000/09/xmldsig#" ' .
            'xmlns:xsd="http://www.w3.org/2001/XMLSchema" ' .
            'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"',
        'xmlns_signedprops' =>
            'xmlns="https://tribunet.hacienda.go.cr/docs/esquemas/2017/v4.2/facturaElectronica" ' .
            'xmlns:ds="http://www.w3.org/2000/09/xmldsig#" ' .
            'xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" ' .
            'xmlns:xsd="http://www.w3.org/2001/XMLSchema" ' .
            'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"',
        'xmlns_signeg' =>
            'xmlns="https://tribunet.hacienda.go.cr/docs/esquemas/2017/v4.2/facturaElectronica" ' .
            'xmlns:ds="http://www.w3.org/2000/09/xmldsig#" ' .
            'xmlns:xsd="http://www.w3.org/2001/XMLSchema" ' .
            'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
        ];
        return $this->_firmarXml($xml, $xmlns);
    }

    /**
     * Firmador de XML
     * 
     * @param string $xml   El xml a firmar
     * @param string $xmlns El namespace del xml
     * 
     * @return string El XML firmado
     */
    private function _firmarXml($xml, $xmlns)
    {
        $xmlService = new XmlService;
        $xmlService->namespaceMap = [
            'http://www.w3.org/2000/09/xmldsig#' => 'ds',
            'http://uri.etsi.org/01903/v1.3.2#' => 'xades'
        ];
        //canoniza todo el documento  para el digest
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->loadXML($xml);
        $docDigest = base64_encode(hash('sha256', $doc->C14N(), true));

        $ns_ds     = '{http://www.w3.org/2000/09/xmldsig#}';
        $ns_xades  = '{http://uri.etsi.org/01903/v1.3.2#}';
        $sha1alg   = 'http://www.w3.org/2000/09/xmldsig#sha1';
        $sha256alg = 'http://www.w3.org/2001/04/xmlenc#sha256';

        $ID               = "ddb543c7-ea0c-4b00-95b9-d4bfa2b4e411";
        $signatureID      = "Signature-$ID";
        $signatureValueId = "SignatureValue-$ID";
        $XadesObjectId    = "XadesObjectId-43208d10-650c-4f42-af80-fc889962c9ac";
        $KeyInfoId        = "KeyInfoId-$signatureID";
        $Reference0Id     = "Reference-0e79b719-635c-476f-a59e-8ac3ba14365d";
        $Reference1Id     = "ReferenceKeyInfo";
        $SignedPropertiesId = "SignedProperties-$signatureID";
        $QualifyingProps  
            = "QualifyingProperties-012b8df6-b93e-4867-9901-83447ffce4bf";
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

        $sigPolicyId = 'https://tribunet.hacienda.go.cr/docs/esquemas/2016/v4.2/'.
        'ResolucionComprobantesElectronicosDGT-R-48-2016_4.2.pdf';
        $sigPolicyHash = 'ZGUwNDAyYWY0MWQ4NDlkYTMxOGI0NjVhNDVhMjc4YWFjZGU2MWRmMg==';

        date_default_timezone_set("America/Costa_Rica");
        $signTime = date('c');

        //--------KeyInfo node----------
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
        // --------Prepare the KeyInfo Digest--------
        $xml_KeyInfo = $xmlService->writeFragment(
            [
                'name' => $ns_ds . 'KeyInfo',
                'attributes' => [
                    'Id' => $KeyInfoId
                ]
            ],
            $KeyInfo
        );
        $xml_KeyInfo = str_replace(
            '<ds:KeyInfo',
            '<ds:KeyInfo ' . $xmlns['xmlns_keyinfo'],
            $xml_KeyInfo
        );
        $keyDigest = $this->_c14DigestSHA256($xml_KeyInfo);

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
            [
                'name' => $ns_ds . 'Reference',
                'attributes' => [
                    'Id' => $Reference0Id,
                    'URI' => ''
                ],
                'value' => [
                    $ns_ds . 'Transforms' => [
                        [
                        'name' => $ns_ds . 'Transform',
                        'attributes' => [
                            'Algorithm' => 
                            'http://www.w3.org/2000/09/xmldsig#enveloped-signature'
                        ]
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
            [
                'name' => $ns_ds . 'Reference',
                'attributes' => [
                    'Id' => $Reference1Id,
                    'URI' => $KeyInfoId
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
        $xml_SignedInfo = $xmlService->writeFragment(
            $ns_ds . 'SignedInfo',
            $SignedInfo
        );
        $xml_SignedInfo = str_replace(
            '<ds:SignedInfo',
            '<ds:SignedInfo ' . $xmlns['xmlns_signeg'],
            $xml_SignedInfo
        );

        $d1p = new \DOMDocument('1.0', 'UTF-8');
        $d1p->loadXML($xml_SignedInfo);
        $xml_SignedInfo = $d1p->C14N();

        $signatureResult = "";
        $algo            = "";

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
                    'attributes' => [
                        'Id' => $SignedPropertiesId,
                    ],
                    'value' => $SignedProperties
                ]
            ]
        ];
        $firma = [
            $ns_ds . 'SignedInfo' => $SignedInfo,
            [
                'name' => $ns_ds . 'SignatureValue',
                'attributes' => [
                    'Id' => $signatureValueId
                ],
                'value' => $SignatureValue
            ],
            [
                'name' => $ns_ds . 'KeyInfo',
                'attributes' => [
                    'Id' => $KeyInfoId
                ],
                'value' => $KeyInfo
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
        return str_replace('</Factura', $xml_firma . "</Factura", $xml);

    }/*
    public function getCert()
    {
        return $this->cert;
    }
    */

    /**
     * C14 SHA256 digest de una cadena
     * 
     * @param string $text El texto para procesar
     * 
     * @return string El Digest
     */
    private function _c14DigestSHA256($text) 
    {
        $digest = str_replace("\r", "", str_replace("\n", "", $text));
        $d = new \DOMDocument('1.0', 'UTF-8');
        $d->loadXML($digest);
        $digest = $d->C14N();
        return base64_encode(hash('sha256', $digest, true));
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
                    '-----END PRIVATE KEY-----'
                ],
                '',
                $key
            )
        );
    }
}