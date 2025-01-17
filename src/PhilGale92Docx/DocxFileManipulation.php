<?php

/**
 * Created by PhpStorm.
 * User: philg
 * Date: 29/04/2018
 * Time: 16:48
 */

namespace PhilGale92Docx;

/**
 * Class DocxFileManipulation
 * @desc The purpose of this class, is pulling the xml structure out,
 * while preparing external references
 * @package PhilGale92Docx
 */
abstract class DocxFileManipulation
{
    /**
     * @var string
     * @desc File path
     */
    private $_baseUri = '';
    /**
     * @var string
     * @desc Raw structure & content XML
     */
    protected $_xmlStructure = '';
    /**
     * @var string
     * @desc Raw Style info XML
     */
    protected $_xmlStyles = '';
    /**
     * @var string
     * @desc Raw Relationship XML
     */
    protected $_xmlRelations = '';
    /**
     * @var FileAttachment[]
     * @desc Track files
     */
    protected $_fileAttachments = [];
    /**
     * @var LinkAttachment[]
     * @desc Track external reference based links
     */
    protected $_linkAttachments = [];
    /**
     * @var Style[]
     */
    protected $_declaredStyles = [];
    /**
     * @var string[]
     */
    protected $_detectedStyles = [];

    /**
     * @var bool|null
     * @desc Track the previous entity_loader flag
     */
    private $_libXmlGlobalLoader = null;

    /**
     * @var string
     */
    private $_fileUri = '';

    /**
     * DocxFileManipulation constructor.
     * @param $fileUri string
     */
    public function __construct($fileUri)
    {
        $this->_fileUri = $fileUri;
    }

    /**
     * @desc Extracts the xml dependencies from the file
     */
    public function parse()
    {
        $fileUri = $this->_fileUri;
        $this->_libXmlGlobalLoader  = libxml_disable_entity_loader();
        $this->_baseUri = $fileUri;
        $this->_extractArchives();
    }

    /**
     * @desc Reset entity_loader to previous value
     */
    public function __destruct()
    {
        if (PHP_VERSION_ID < 80000) {
            libxml_disable_entity_loader($this->_libXmlGlobalLoader);
        }
    }

    /**
     * @param $imageName string
     * @param $imageData string
     * @desc Override if you want to extend the fileAttachment object to be customised
     * @return FileAttachment
     */
    protected function _createFileAttachmentFromSource(
        $imageName,
        $imageData
    ) {
        return new FileAttachment(
            $imageName,
            $imageData
        );
    }

    /**
     * @desc Unzip and track the useful files
     *  - We need to track relationships, the main structure and any image assets
     */
    private function _extractArchives()
    {
        $zipArchive = zip_open($this->_baseUri);
        while ($zipEntry = zip_read($zipArchive)) {
            $entryName = zip_entry_name($zipEntry);
            if (zip_entry_open($zipArchive, $zipEntry) == false) continue;

            if ($entryName == 'word/_rels/document.xml.rels') {
                $this->_xmlRelations = zip_entry_read($zipEntry, zip_entry_filesize($zipEntry));
            } else if ($entryName == 'word/document.xml') {
                $this->_xmlStructure = zip_entry_read($zipEntry, zip_entry_filesize($zipEntry));
            } else if ($entryName == 'word/styles.xml') {
                $this->_xmlStyles = zip_entry_read($zipEntry, zip_entry_filesize($zipEntry));
            } else if (strpos($entryName, 'word/media') !== false) {
                # Removes 'word/media' prefix
                $imageName = substr($entryName, 11);

                /*
                 * Prevent EMF file extensions passing,
                 * Ref https://github.com/PhilGale92/docx/issues/2
                 */
                if (substr($imageName, -3) == 'emf') continue;

                $imageData = base64_encode(
                    zip_entry_read($zipEntry, zip_entry_filesize($zipEntry))
                );
                $this->_fileAttachments[$imageName] = $this->_createFileAttachmentFromSource($imageName, $imageData);
            }
            zip_entry_close($zipEntry);
        }
        zip_close($zipArchive);


        $this->_processRelationships();
        $this->_processStyleInfo();
    }



    /**
     * @desc Process the xmlRelations into link
     * mappings, and pull out any additional image data that is available !
     */
    private function _processRelationships()
    {
        if ($this->_xmlRelations != '') {
            $dom = new \DOMDocument();
            $dom->loadXML($this->_xmlRelations, LIBXML_NOENT | LIBXML_XINCLUDE | LIBXML_NOERROR | LIBXML_NOWARNING);
            $dom->encoding = 'utf-8';
            $elements = $dom->getElementsByTagName('Relationship');
            foreach ($elements as $node) {
                $relationshipAttributes = $node->attributes;
                $relationId = $relationshipAttributes->item(0);
                $relationType = $relationshipAttributes->item(1);
                $relationTarget = $relationshipAttributes->item(2);
                /*
                 * Now split the links from image assets
                 */
                if (is_object($relationId) && is_object($relationTarget)) {
                    $linkupId = $relationId->nodeValue;
                    if (stripos($relationType->nodeValue, 'relationships/hyperlink') !== false) {
                        $this->_linkAttachments[$linkupId] = new LinkAttachment(
                            $linkupId,
                            $relationTarget->nodeValue
                        );
                    } else if (stripos($relationType->nodeValue, 'relationships/image') !== false) {
                        $imageName = substr($relationTarget->nodeValue, 6);
                        if(isset($this->_fileAttachments[$imageName])){
                            $this->_fileAttachments[$imageName]->setLinkupId($linkupId);
                        }
                    }
                }
            }
        }
    }

    /**
     * @desc Process the style info
     */
    private function _processStyleInfo()
    {
        $dom = new \DOMDocument();
        $dom->loadXML($this->_xmlStyles, LIBXML_NOENT | LIBXML_XINCLUDE | LIBXML_NOERROR | LIBXML_NOWARNING);
        $dom->encoding = 'utf-8';
        $styleElements = $dom->getElementsByTagName('style');
        foreach ($styleElements as $styleElement) {
            /**
             * @var $styleElement \DOMElement
             */
            $validStyleCore = 0;
            $styleId = null;
            foreach ($styleElement->attributes as $attribute) {
                /**
                 * @var $attribute \DOMAttr
                 */
                if ($attribute->nodeName == 'w:customStyle' && $attribute->nodeValue == 1) {
                    $validStyleCore++;
                }
                if ($attribute->nodeName == 'w:styleId' && $attribute->nodeValue != '') {
                    $styleId = $attribute->nodeValue;
                    $validStyleCore++;
                }
            }
            if ($validStyleCore < 2) continue;
            $this->_detectedStyles[] = $styleId;
        }
    }
}
