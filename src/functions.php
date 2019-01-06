<?php

namespace Andig;

use Andig\CardDav\Backend;
use Andig\Vcard\Parser;
use Andig\FritzBox\Converter;
use Andig\FritzBox\Api;
use \SimpleXMLElement;

/**
 * Initialize backend from configuration
 *
 * @param array $config
 * @return Backend
 */
function backendProvider(array $config): Backend
{
    $server = $config['server'] ?? $config;
    $authentication = $server['authentication'] ?? null;

    $backend = new Backend();
    $backend->setUrl($server['url']);
    $backend->setAuth($server['user'], $server['password'], $authentication);

    return $backend;
}

/**
 * Download vcards from CardDAV server
 *
 * @param Backend $backend
 * @param callable $callback
 * @return array
 */
function download(Backend $backend, $substitutes, callable $callback=null): array
{
    $backend->setProgress($callback);
    $backend->setSubstitutes($substitutes);
    return $backend->getVcards();
}

/**
 * upload image files via ftp to the fritzbox fonpix directory
 *
 * @param $vcards     array     downloaded vCards
 * @param $config     array
 * @return            array     number of uploaded/refreshed images; number of total found images
 */
function uploadImages(array $vcards, $config, callable $callback=null)
{
    $countUploadedImages = 0;
    $countAllImages = 0;

    // Prepare FTP connection
    $ftpserver = $config['url'];
    $ftpserver = str_replace("http://", "", $ftpserver);
    $ftp_conn = ftp_connect($ftpserver);
    if (!$ftp_conn) {
        error_log("ERROR: Could not connect to ftp server ".$ftpserver." for image upload.");
        return false;
    }
    if (!ftp_login($ftp_conn, $config['user'], $config['password'])) {
        error_log("ERROR: Could not log in ".$config['user']." to ftp server ".$ftpserver." for image upload.");
        return false;
    }
    if (!ftp_chdir($ftp_conn, $config['fonpix'])){
        error_log("ERROR: Could change to dir ".$config['fonpix']." on ftp server ".$ftpserver." for image upload.");
        return false;
    }
    foreach ($vcards as $vcard) {
        if (is_callable($callback)) {
            ($callback)();
        }

        if (isset($vcard->rawPhoto)) {                                     // skip all other vCards
            if (preg_match("/JPEG/", strtoupper(substr($vcard->photoData, 0, 256)))) {     // Fritz!Box only accept jpg-files
                $countAllImages++;
                $remotefilename = sprintf('%1$s.jpg', $vcard->uid);
                // We only upload if filesize differs. Non existing server file will have size -1
                if (ftp_size($ftp_conn, $remotefilename) == strlen($vcard->rawPhoto)) {
                    continue;
                }
                $memstream = fopen('php://memory', 'r+');     // we use a fast in-memory file stream
                fputs($memstream, $vcard->rawPhoto);
                rewind($memstream);

                // upload file
                if (ftp_fput($ftp_conn, $remotefilename, $memstream, FTP_BINARY)){
                    $countUploadedImages++;
                } else {
                    error_log("Error uploading $remotefilename.\n");
                    unset($vcard->rawPhoto);                           // no wrong link will set in phonebook
                }
                fclose($memstream);
            }
        }
    }
    ftp_close($ftp_conn);
    error_log("\n");

    return array($countUploadedImages, $countAllImages);
}

/**
 * Dissolve the groups of iCloud contacts
 *
 * @param array $cards
 * @return array
 */
function dissolveGroups (array $vcards): array
{
    $groups = [];

    foreach ($vcards as $key => $vcard) {          // separate iCloud groups
        if (isset($vcard->xabsmember)) {
            if (array_key_exists($vcard->fullname, $groups)) {
                $groups[$vcard->fullname] = array_merge($groups[$vcard->fullname], $vcard->xabsmember);
            } else {
                $groups[$vcard->fullname] = $vcard->xabsmember;
            }
            unset($vcards[$key]);
            continue;
        }
    }
    $vcards = array_values($vcards);
    // assign group memberships
    foreach ($vcards as $vcard) {
        foreach ($groups as $group => $members) {
            if (in_array($vcard->uid, $members)) {
                if (!isset($vcard->group)) {
                    $vcard->group = array();
                }
                $vcard->group = $group;
                break;
            }
        }
    }
    return $vcards;
}

/**
 * Filter included/excluded vcards
 *
 * @param array $cards
 * @param array $filters
 * @return array
 */
function filter(array $cards, array $filters): array
{
    // include selected
    $includeFilter = $filters['include'] ?? [];

    if (countFilters($includeFilter)) {
        $step1 = [];

        foreach ($cards as $card) {
            if (filtersMatch($card, $includeFilter)) {
                $step1[] = $card;
            }
        }
    }
    else {
        // filter defined but empty sub-rules?
        if (count($includeFilter)) {
            error_log('Include filter empty- including all cards');
        }

        // include all by default
        $step1 = $cards;
    }

    $excludeFilter = $filters['exclude'] ?? [];
    if (!count($excludeFilter)) {
        return $step1;
    }

    $step2 = [];
    foreach ($step1 as $card) {
        if (!filtersMatch($card, $excludeFilter)) {
            $step2[] = $card;
        }
    }

    return $step2;
}

/**
 * Count populated filter rules
 *
 * @param array $filters
 * @return int
 */
function countFilters(array $filters): int
{
    $filterCount = 0;

    foreach ($filters as $key => $value) {
        if (is_array($value)) {
            $filterCount += count($value);
        }
    }

    return $filterCount;
}

/**
 * Check a list of filters against a card
 *
 * @param [type] $card
 * @param array $filters
 * @return bool
 */
function filtersMatch($card, array $filters): bool
{
    foreach ($filters as $attribute => $values) {
        if (isset($card->$attribute)) {
            if (filterMatches($card->$attribute, $values)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Check a filter against a single attribute
 *
 * @param [type] $attribute
 * @param [type] $filterValues
 * @return bool
 */
function filterMatches($attribute, $filterValues): bool
{
    if (!is_array($filterValues)) {
        $filterValues = array($filterMatches);
    }

    foreach ($filterValues as $filter) {
        if (is_array($attribute)) {
            // check if any attribute matches
            foreach ($attribute as $childAttribute) {
                if ($childAttribute === $filter) {
                    return true;
                }
            }
        } else {
            // check if simple attribute matches
            if ($attribute === $filter) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Export cards to fritzbox xml
 *
 * @param array $cards
 * @param array $conversions
 * @return SimpleXMLElement
 */
function export(array $cards, array $conversions): SimpleXMLElement
{
    $xml = new SimpleXMLElement(
        <<<EOT
<?xml version="1.0" encoding="UTF-8"?>
<phonebooks>
<phonebook />
</phonebooks>
EOT
    );

    $root = $xml->xpath('//phonebook')[0];
    $root->addAttribute('name', $conversions['phonebook']['name']);

    $converter = new Converter($conversions);

    foreach ($cards as $card) {
        $contacts = $converter->convert($card);
        foreach ($contacts as $contact) {
            xml_adopt($root, $contact);
        }
    }
    return $xml;
}

/**
 * Attach xml element to parent
 * https://stackoverflow.com/questions/4778865/php-simplexml-addchild-with-another-simplexmlelement
 *
 * @param SimpleXMLElement $to
 * @param SimpleXMLElement $from
 * @return void
 */
function xml_adopt(SimpleXMLElement $to, SimpleXMLElement $from)
{
    $toDom = dom_import_simplexml($to);
    $fromDom = dom_import_simplexml($from);
    $toDom->appendChild($toDom->ownerDocument->importNode($fromDom, true));
}

/**
 * Upload cards to fritzbox
 *
 * @param string $xml
 * @param array $config
 * @return void
 */
function upload(string $xml, $config)
{
    getOldPhonebook($config);

    $fritzbox = $config['fritzbox'];

    $fritz = new Api($fritzbox['url'], $fritzbox['user'], $fritzbox['password']);

    $formfields = array(
        'PhonebookId' => $config['phonebook']['id']
    );

    $filefields = array(
        'PhonebookImportFile' => array(
            'type' => 'text/xml',
            'filename' => 'updatepb.xml',
            'content' => $xml,
        )
    );

    $result = $fritz->doPostFile($formfields, $filefields); // send the command

    if (strpos($result, 'Das Telefonbuch der FRITZ!Box wurde wiederhergestellt') === false) {
        throw new \Exception('Upload failed');
    }
}

/**
 * Downloads the old phone book from Fritzbox
 *
 * @param array $config
 * @return SimpleXMLElement with the old phonebook
 */
function getOldPhonebook($config)
{
    $fritzbox = $config['fritzbox'];
    $fritz = new Api($fritzbox['url'], $fritzbox['user'], $fritzbox['password']);
    $formfields = array(
        'PhonebookId' => $config['phonebook']['id'],
        'PhonebookExportName' => $config['phonebook']['name'],
        'PhonebookExport' => "",
    );
    $result = $fritz->doPostFile($formfields); // send the command
    if (substr($result, 0, 5) !== "<?xml") {
        error_log("ERROR: Could not load old phonebook with ID=".$config['phonebook']['id']);
        return false;
    }
    $XMLPhonebook = simplexml_load_string($result);
    return $XMLPhonebook;
}


function getQuickDials ($XMLPhonebook) {
    $quickdials = [];
    foreach($XMLPhonebook->phonebook->contact as $contact)
    {
        echo $contact->carddav_uid."\n";
        foreach ($contact->telephony->number as $number) {
            if (isset($number->attributes()->quickdial)) {
                // build unique key: {normalized-phone-number}@{vCard UUID} mapping to quick dial number
                $key = preg_replace("/[^\+0-9]/", "", $number)."@".$contact->carddav_uid;
                $quickdials[$key] = $number->attributes()->quickdial."";    // force to string
            }
        }
    }
    return $quickdials;
}
