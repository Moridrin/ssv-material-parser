<?php

namespace ssv_material_parser;

use \DOMDocument;
use simple_html_dom;
use Wizardawn\Models\City;

require_once "Parsers/MapParser.php";
require_once "Parsers/NPCParser.php";
require_once "Parsers/RulersParser.php";
require_once "Parsers/BuildingParser.php";

/**
 * Created by PhpStorm.
 * User: moridrin
 * Date: 14-6-17
 * Time: 7:15
 */
abstract class Converter extends Parser
{
    /**
     * This function converts a HTML string as generated by the Wizardawn Fantasy Settlements Generator to arrays with all the data in the HTML.
     *
     * @param string $content
     *
     * @return array
     */
    public static function Convert(string $content)
    {
        $content = self::cleanCode($content);
        $content = self::bugFixes($content);
        $html = str_get_html($content);

        $city = new City();
        $city->setTitle($html->getElementByTagName('font')->text());
        $city->setMap(MapParser::parseMap($html));



        mp_var_export($city, true);

        $objects = array('buildings' => array());
        foreach ($parts as $key => &$part) {
            switch ($key) {
                case 'map':
                    $objects['map'] = MapParser::getParser()->parseMap($part);
                    break;
                case 'ruler':
                    $objects['rulers'] = RulersParser::parseRulersBuilding($part);
                    break;
                case 'npcs':
                    NPCParser::getParser()->parseNPCs($part);
                    BuildingParser::parseBuildings($part, 'houses');
                    break;
                case 'merchants':
                case 'guardhouses':
                case 'churches':
                case 'guilds':
                    BuildingParser::parseBuildings($part, $key);
                    break;
                case 'banks':
                    mp_var_export('Banks aren\'t implemented yet.');
                    break;
                case 'title':
                    $part = self::cleanCode($part);
                    $file = new DOMDocument();
                    libxml_use_internal_errors(true);
                    $file->loadHTML($part);
                    $objects['title'] = $file->getElementsByTagName('font')->item(0)->firstChild->textContent;
            }

            $part = self::finalizePart($part);
        }
        $objects['npcs']      = NPCParser::getParser()->getNPCs();
        $objects['buildings'] = BuildingParser::getBuildings();
        return $objects;
    }

    /**
     * This function fixes all bugs in the original generated code from the generated Wizardawn HTML.
     *
     * @param string $content
     *
     * @return string
     */
    private static function bugFixes($content)
    {
        $file = new DOMDocument();
        libxml_use_internal_errors(true);
        $file->loadHTML($content);
        $body         = $file->getElementsByTagName('body')->item(0);
        $baseElements = $body->childNodes;
        for ($i = 0; $i < $baseElements->length; $i++) {
            $html = $file->saveHTML($baseElements->item($i));
            if (strpos($html, 'wtown_01.jpg') !== false) {
                $badCode = trim($file->saveHTML($baseElements->item($i + 2)->childNodes->item(0)));
            }
        }
        if (isset($badCode)) {
            $html = $file->saveHTML();
            $html = str_replace($badCode, $badCode . '</font>', $html);
            $file->loadHTML($html);
        }
        return self::cleanCode($file->saveHTML());
    }

    /**
     * This function converts the raw HTML string to an array of raw HTML strings grouped by part.
     *
     * @param string $content
     *
     * @return string[]
     */
    private static function splitInParts($content)
    {
        $file = new DOMDocument();
        libxml_use_internal_errors(true);
        $file->loadHTML($content);
        $body         = $file->getElementsByTagName('body')->item(0);
        $baseElements = $body->childNodes;

        $parts  = array();
        $filter = 'map';
        for ($i = 0; $i < $baseElements->length; $i++) {
            $baseElement = $baseElements->item($i);
            $html        = $file->saveHTML($baseElement);
            if ($filter == 'map' && strpos($html, '<hr>') !== false) {
                $filter = 'title';
                continue;
            }
            if (strpos($html, 'wtown_01.jpg') !== false) {
                $filter = 'npcs';
                continue;
            }
            if (strpos($html, 'wtown_02.jpg') !== false) {
                $filter = 'ruler';
                continue;
            }
            if (strpos($html, 'wtown_03.jpg') !== false) {
                $filter = 'guardhouses';
                continue;
            }
            if (strpos($html, 'wtown_04.jpg') !== false) {
                $filter = 'churches';
                continue;
            }
            if (strpos($html, 'wtown_05.jpg') !== false) {
                $filter = 'banks';
                continue;
            }
            if (strpos($html, 'wtown_06.jpg') !== false) {
                $filter = 'merchants';
                continue;
            }
            if (strpos($html, 'wtown_07.jpg') !== false) {
                $filter = 'guilds';
                continue;
            }
            if (!isset($parts[$filter])) {
                $parts[$filter] = '';
            }
            $parts[$filter] .= trim($html);
        }
        return array_filter($parts);
    }
}
