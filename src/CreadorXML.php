<?php

/**
 * Clase con las funciones para crear los archivos XML y firmarlos
 *
 * PHP version 7.4
 *
 * @category  Facturacion-electronica
 * @package   Contica\Facturacion
 * @author    Josias Martin <josias@solucionesinduso.com>
 * @copyright 2020 Josias Martin
 * @license   https://opensource.org/licenses/MIT MIT
 * @version   GIT: <git-id>
 * @link      https://github.com/josiasmc/facturador-electronico-cr
 */

namespace Contica\Facturacion;

use Ramsey\Uuid\Uuid;

/**
 * Todas los metodos para crear los archivos XML
 *
 * @category Facturacion-electronica
 * @package  Contica\Facturacion\CreadorXML
 * @author   Josias Martin <josias@solucionesinduso.com>
 * @license  https://opensource.org/licenses/MIT MIT
 * @version  Release: <package-version>
 * @link     https://github.com/josiasmc/facturador-electronico-cr
 */
class CreadorXML
{
    protected $publicKey;
    protected $certData;
    protected $privateKey;
    protected $modulus;
    protected $exponent;
    protected $version;

    /**
     * Class constructor
     *
     * @param array $container Container with settings
     */
    public function __construct($container, $version = '4.3')
    {
        $empresas = new Empresas($container);
        $id = $container['id'];
        //$this->version = $version;
        if ($keys = $empresas->getCert($id)) {
            $pin = $keys['pin'];
            $cert = $keys['llave'];
            $read = openssl_pkcs12_read($cert, $key, $pin);
            if ($read == false) {
                throw new \Exception('Error al abrir la llave criptográfica.');
            }
            $certData = openssl_x509_parse($key['cert']);
            $validTo = $certData['validTo_time_t'];
            if (time() > $validTo) {
                throw new \Exception('La llave criptográfica ha caducado. Por favor genere una nueva.');
            }
            $this->publicKey  = $key["cert"];
            $this->privateKey = $key["pkey"];
            $this->certData = $certData;

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
        if (isset($datos['NumeroConsecutivoReceptor'])) {
            //Este es un mensaje de confirmacion
            $consecutivo = $datos['NumeroConsecutivoReceptor'];
        } else {
            //Es una factura
            $consecutivo = $datos['NumeroConsecutivo'];
        }
        $tipo = substr($consecutivo, 8, 2);
        $ns = 'https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.3/';

        switch ($tipo) {
            // Factura electronica
            case '01':
                $ns .= 'facturaElectronica';
                $root_element = 'FacturaElectronica';
                break;
            // Nota de debito electronico
            case '02':
                $ns .= 'notaDebitoElectronica';
                $root_element = 'NotaDebitoElectronica';
                break;
            // Nota de credito electronico
            case '03':
                $ns .= 'notaCreditoElectronica';
                $root_element = 'NotaCreditoElectronica';
                break;
            // Tiquete electronico
            case '04':
                $ns .= 'tiqueteElectronico';
                $root_element = 'TiqueteElectronico';
                break;
            // Confirmacion de aceptacion del comprobante electronico
            case '05':
            // Confirmacion de aceptacion parcial del comprobante electronico
            case '06':
            // Confirmacion de rechazo del comprobante electronico
            case '07':
                $ns .= 'mensajeReceptor';
                $root_element = 'MensajeReceptor';
                break;
            // Factura electronica de compra
            case '08':
                $ns .= 'facturaElectronicaCompra';
                $root_element = 'FacturaElectronicaCompra';
                break;
            // Factura electronica de exportacion
            case '09':
                $ns .= 'facturaElectronicaExportacion';
                $root_element = 'FacturaElectronicaExportacion';
                break;
            // Quien sabe que mandaron
            default:
                return false;
        }
        $xmlService = new XmlService();
        $xmlService->namespaceMap = [
            'http://www.w3.org/2001/XMLSchema' => 'xsd',
            'http://www.w3.org/2001/XMLSchema-instance' => 'xsi',
            $ns => ''
        ];
        $xml = $xmlService->write('{' . $ns . '}' . $root_element, $datos);
        return $this->firmarXml($xml, $ns);
    }

    /**
     * Firmador de XML
     *
     * @param string $xml El xml a firmar
     * @param string $ns  El namespace del xml
     *
     * @return string El XML firmado
     */
    private function firmarXml($xml, $ns)
    {
        // Definir los namespace para los diferentes nodos
        $ns_ds    = 'http://www.w3.org/2000/09/xmldsig#';
        $ns_xsd   = 'http://www.w3.org/2001/XMLSchema';
        $ns_xsi   = 'http://www.w3.org/2001/XMLSchema-instance';
        $ns_xades = 'http://uri.etsi.org/01903/v1.3.2#';
        $xmlns = [
            'keyinfo' =>
                "xmlns=\"$ns\" xmlns:ds=\"$ns_ds\" xmlns:xsd=\"$ns_xsd\" xmlns:xsi=\"$ns_xsi\"",
            'signedprops' =>
                "xmlns=\"$ns\" xmlns:ds=\"$ns_ds\" xmlns:xades=\"$ns_xades\" xmlns:xsd=\"$ns_xsd\" xmlns:xsi=\"$ns_xsi\"",
            'signed' =>
                "xmlns=\"$ns\" xmlns:ds=\"$ns_ds\" xmlns:xsd=\"$ns_xsd\" xmlns:xsi=\"$ns_xsi\""
            ];

        $xmlService = new XmlService();
        $xmlService->namespaceMap = [
            $ns_ds => 'ds',
            $ns_xades => 'xades'
        ];

        //canoniza todo el documento y crea el digest
        $xml = preg_replace('/>\s+</', '><', $xml); //Comprimir xml
        $docDigest = $this->c14DigestSHA256($xml);

        $ds     = '{http://www.w3.org/2000/09/xmldsig#}';
        $xades  = '{http://uri.etsi.org/01903/v1.3.2#}';
        $sha256alg = 'http://www.w3.org/2001/04/xmlenc#sha256';

        $ID                 = (Uuid::uuid4())->toString();
        $signatureID        = "S-$ID";
        $signatureValueId   = "SV-$ID";
        $XadesObjectId      = "XO-$ID";
        $KeyInfoId          = "KI-$ID";
        $Reference0Id       = "R0-$ID";
        $Reference1Id       = "R1-$ID";
        $SignedPropertiesId = "SP-$ID";
        $QualifyingProps    = "QP-$ID";

        // Certificate digest
        $certDigest = base64_encode(
            openssl_x509_fingerprint($this->publicKey, "sha256", true)
        );

        // Certificate issuer
        $certData   = $this->certData;
        $issuers    = $certData['issuer'];
        $certIssuer = array_reduce(
            array_keys($issuers),
            function ($c, $i) use ($issuers) {
                $c = $c ? "$c, " : '';
                return "$c$i={$issuers[$i]}";
            }
        );

        $sigPolicyId = 'https://www.hacienda.go.cr/ATV/ComprobanteElectronico/docs/esquemas/2016/v4.3/Resoluci%C3%B3n_General_sobre_disposiciones_t%C3%A9cnicas_comprobantes_electr%C3%B3nicos_para_efectos_tributarios.pdf';
        $sigPolicyHash = '0h7Q3dFHhu0bHbcZEgVc07cEcDlquUeG08HG6Iototo='; //hash en sha256
        // CALCULADO CON sha256sum sobre el archivo descargado
        // echo {sha256sum} | xxd -r -p | base64

        date_default_timezone_set("America/Costa_Rica");
        $signTime = date('c');

        //---------------Crear los objetos a firmar----------------------
        
        //----------------------Objeto KeyInfo---------------------------
        $KeyInfo = [
            $ds . 'X509Data' => [
                $ds . 'X509Certificate' => $this->cleanKey($this->publicKey)
            ],
            $ds . 'KeyValue' => [
                $ds . 'RSAKeyValue' => [
                    $ds . 'Modulus' => $this->modulus,
                    $ds . 'Exponent' => $this->exponent
                ]
            ]
        ];

        //----------------Prepare the KeyInfo Digest---------------------
        $xml_KeyInfo = $xmlService->writeFragment(
            [
                'name' => $ds . 'KeyInfo',
                'attributes' => [
                    'Id' => $KeyInfoId
                ]
            ],
            $KeyInfo
        );
        $xml_KeyInfo = preg_replace('/>\s+</', '><', $xml_KeyInfo); // COMPRIMIR
        $xmlDsKeyInfo = trim($xml_KeyInfo);
        $xml_KeyInfo = str_replace(
            '<ds:KeyInfo',
            '<ds:KeyInfo ' . $xmlns['keyinfo'],
            $xml_KeyInfo
        );
        $keyDigest = $this->c14DigestSHA256(trim($xml_KeyInfo));

        //-------SignedProperties node--------
        $SignedProperties = [
            $xades . 'SignedSignatureProperties' => [
                $xades . 'SigningTime' => $signTime,
                $xades . 'SigningCertificate' => [
                    $xades . 'Cert' => [
                        $xades . 'CertDigest' => [
                            [
                                'name' => $ds . 'DigestMethod',
                                'attributes' => [
                                    'Algorithm' => $sha256alg
                                ]
                            ],
                            $ds . 'DigestValue' => $certDigest//certDigest
                        ],
                        $xades . 'IssuerSerial' => [
                            $ds . 'X509IssuerName' => $certIssuer,//certIssuer
                            $ds . 'X509SerialNumber' => $certData['serialNumber']//certSN
                        ]
                    ]
                ],
                $xades . 'SignaturePolicyIdentifier' => [
                    $xades . 'SignaturePolicyId' => [
                        $xades . 'SigPolicyId' => [
                            $xades . 'Identifier' => $sigPolicyId,//sigPolicyId
                            [
                                'name' => $xades . 'Description',
                                'attributes' => []
                            ]
                        ],
                        $xades . 'SigPolicyHash' => [
                            [
                                'name' => $ds . 'DigestMethod',
                                'attributes' => [
                                    'Algorithm' => $sha256alg]],
                            $ds . 'DigestValue' => $sigPolicyHash//sigPolicyHash
                        ]
                    ]
                ],
            ],
            $xades . 'SignedDataObjectProperties' => [
                [
                    'name' => $xades . 'DataObjectFormat',
                    'attributes' => [
                        'ObjectReference' => '#' . $Reference0Id
                    ],
                    'value' => [
                        $xades . 'MimeType' => 'text/xml',
                        $xades . 'Encoding' => 'UTF-8'
                    ]
                ]
            ]
            
        ];
        $xml_SignedProperties = $xmlService->writeFragment(
            [
                'name' => $xades . 'SignedProperties',
                'attributes' => [
                    'Id' => $SignedPropertiesId,
                ]
            ],
            $SignedProperties
        );
        $xml_SignedProperties = preg_replace('/>\s+</', '><', $xml_SignedProperties); // COMPRIMIR
        $xmlDsSignedProperties = trim($xml_SignedProperties);
        $xml_SignedProperties = str_replace(
            '<xades:SignedProperties',
            '<xades:SignedProperties ' . $xmlns['signedprops'],
            $xml_SignedProperties
        );
        $propDigest = $this->c14DigestSHA256($xml_SignedProperties);

        $SignedInfo = [
            [
            'name' => $ds . 'CanonicalizationMethod',
            'attributes' => [
                'Algorithm' => 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315'
                ]
            ],
            [
            'name' => $ds . 'SignatureMethod',
            'attributes' => [
                'Algorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256'
                ]
            ],
            // Reference for the XML document
            [
                'name' => $ds . 'Reference',
                'attributes' => [
                    'Id' => $Reference0Id,
                    'URI' => ''
                ],
                'value' => [
                    $ds . 'Transforms' => [
                        'name' => $ds . 'Transform',
                        'attributes' => [
                            'Algorithm' =>
                            'http://www.w3.org/2000/09/xmldsig#enveloped-signature'
                        ]
                    ],
                    [
                        'name' => $ds . 'DigestMethod',
                        'attributes' => [
                            'Algorithm' => $sha256alg
                        ]
                    ],
                    $ds . 'DigestValue' => $docDigest
                ]
            ],
            // Reference for KeyInfo object
            [
                'name' => $ds . 'Reference',
                'attributes' => [
                    'Id' => $Reference1Id,
                    'URI' => '#' . $KeyInfoId
                ],
                'value' => [
                    [
                        'name' => $ds . 'DigestMethod',
                        'attributes' => [
                            'Algorithm' => $sha256alg
                        ]
                    ],
                    $ds . 'DigestValue' => $keyDigest
                ]
            ],
            // Reference for SignedProperties object
            [
                'name' => $ds . 'Reference',
                'attributes' => [
                    'Type' => 'http://uri.etsi.org/01903#SignedProperties',
                    'URI' => "#$SignedPropertiesId"
                ],
                'value' => [
                    [
                        'name' => $ds . 'DigestMethod',
                        'attributes' => [
                            'Algorithm' => $sha256alg
                        ]
                    ],
                    $ds . 'DigestValue' => $propDigest
                ]
            ]
        ];
        // El elemento SignedInfo es el que se firma criptograficamente
        $xml_SignedInfo = $xmlService->writeFragment(
            $ds . 'SignedInfo',
            $SignedInfo
        );
        $xml_SignedInfo = preg_replace('/>\s+</', '><', $xml_SignedInfo); // COMPRIMIR
        $xmlDsSignedInfo = trim($xml_SignedInfo);
        $xml_SignedInfo = str_replace(
            '<ds:SignedInfo',
            '<ds:SignedInfo ' . $xmlns['signed'],
            $xml_SignedInfo
        );

        $d1p = new \DOMDocument('1.0', 'UTF-8');
        $d1p->loadXML($xml_SignedInfo);
        $xml_SignedInfo = $d1p->C14N(false, false);

        // La firma es el resultado de firmar el nodo SignedInfo
        openssl_sign(
            $xml_SignedInfo,
            $signatureResult,
            $this->privateKey,
            'SHA256'
        );

        $SignatureValue = base64_encode($signatureResult);//Signature value
        
        $Object = [
            [
                'name' => $xades . 'QualifyingProperties',
                'attributes' => [
                    'Id' => $QualifyingProps,
                    'Target' => "#$signatureID"
                ],
                'value' => [
                    'name' => $xades . 'SignedProperties',
                    'value' => ''
                ]
            ]
        ];
        $firma = [
            [
                'name' => $ds . 'SignedInfo',
                'value' => ''
            ],
            [
                'name' => $ds . 'SignatureValue',
                'attributes' => [
                    'Id' => $signatureValueId
                ],
                'value' => $SignatureValue
            ],
            [
                'name' => $ds . 'KeyInfo',
                'value' => ''
            ],
            [
                'name' => $ds . 'Object',
                'attributes' => [
                    'Id' => $XadesObjectId
                ],
                'value' => $Object
            ]
        ];
        
        $xml_firma =  $xmlService->writeSignature(
            [
                'name' => $ds . 'Signature',
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
        $xml_firma = preg_replace('/>\s+</', '><', $xml_firma); // comprimir
        // Insertar la firma en el documento xml
        $rPos = strrpos($xml, '</', strlen($xml) - 30);
        $rStr = substr($xml, $rPos);
        return str_replace($rStr, rtrim($xml_firma) . $rStr, $xml);
    }

    /**
     * C14N SHA256 digest de una cadena
     *
     * @param string $xml El texto para procesar
     *
     * @return string El Digest
     */
    private function c14DigestSHA256($xml)
    {
        $d = new \DOMDocument('1.0', 'UTF-8');
        $d->loadXML($xml);
        return base64_encode(hash('sha256', $d->C14N(false, false), true));
    }

    /**
     * Limpiador de llave criptografica
     *
     * @param string $key La llave
     *
     * @return string La llave limpiada
     */
    private function cleanKey($key)
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
}
