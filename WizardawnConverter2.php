<?php

namespace ssv_material_parser;

use \DOMDocument;

require_once "Wizardawn/MapParser.php";
require_once "Wizardawn/NPCParser.php";
require_once "Wizardawn/BuildingParser.php";

/**
 * Created by PhpStorm.
 * User: moridrin
 * Date: 14-6-17
 * Time: 7:15
 */
abstract class WizardawnConverter extends Parser
{
    private static $objects;

    /**
     * This function converts a HTML string as generated by the Wizardawn Fantasy Settlements Generator to arrays with all the data in the HTML.
     *
     * @param string $content
     *
     * @return array
     */
    public static function Convert($content)
    {
        $content = self::cleanCode($content);
        $content = self::bugFixes($content);
        $parts   = self::splitInParts($content);

        foreach ($parts as $key => &$part) {
            switch ($key) {
                case 'map':
                    self::$objects['map'] = MapParser::parseMap($part);
                    break;
                case 'npcs':
                    self::$objects['npcs'] = NPCParser::parseNPCs($part);
                    break;
                case 'guards':
                case 'churches':
                case 'banks':
                case 'merchants':
                case 'guilds':
                    self::$objects['buildings'] = BuildingParser::parseBuildings($part);
                    $part                  = isset($parts['npcs']) ? self::appendToBuildings($part) : self::parseBuildings($part);
                    break;
            }
            $part = self::finalizePart($part);
        }

        foreach (self::$buildings as $id => &$building) {
            $building = self::finalizePart("<div id=\"modal_$id\" class=\"modal\"><div class=\"modal-content\">" . self::parseNPCs($building, $id) . "</div></div>");
        }
        $parts['buildings'] = self::finalizePart(implode('', self::$buildings));

        if (isset($parts['npcs'])) {
            $emptyHouses     = '';
            $filterBuildings = $parts;
            unset($filterBuildings['map']);
            unset($filterBuildings['npcs']);
            $fullHTML = self::cleanCode(implode('', $filterBuildings));
            if (preg_match_all("/.*?href=\"#modal_([0-9]+)\".*?<br\/>/", $parts['npcs'], $buildingSearces)) {
                for ($i = 0; $i < count($buildingSearces[0]); $i++) {
                    $search = $buildingSearces[1][$i];
                    if (strpos($fullHTML, "href=\"#modal_$search\"") === false) {
                        $emptyHouses .= $buildingSearces[0][$i];
                    }
                }
            }
            $parts['npcs'] = $emptyHouses;
        }

        return $parts;
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
                $filter = 'guards';
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

    /**
     * This function parses the raw HTML code from a part, saves the buildings to the buildings array and returns the HTML code for the part with links to open the buildings as modals.
     *
     * @param string $basePart
     *
     * @return array
     */
    private static function parseBuildings($basePart)
    {
        $basePart  = preg_replace("/<font size=\"3\">([0-9]+)<\/font>/", "##START##$0", $basePart);
        $basePart  = str_replace(array('<b><i>', '</i></b>'), '', $basePart);
        $basePart  = str_replace('</i><b>', '</i>', $basePart);
        $buildings = preg_split("/##START##/", $basePart);
        $newParts  = array();
        foreach ($buildings as $building) {
            if (preg_match_all("/<font size=\"3\">([0-9]+)<\/font>/", $building, $ids)) {
                $id    = $ids[1][0];
                $title = "Building $id";
                if (preg_match("/-<b>(.*?)<\/b>/", $building, $titles)) {
                    // Citizens start with their name followed by a ':' where Merchants, Guilds, etc. just have the name (not followed by a ':').
                    if (!mp_ends_with($titles[1], ':') !== false) {
                        $title    = $titles[1] . " (Building $id)";
                        $building = trim(str_replace($titles[0], '', $building));
                    }
                }
                self::$buildings[$id] = preg_replace("/<font size=\"3\">$id<\/font>/", "<h1>$title</h1>", $building);
                $newParts[]           = "<a class=\"modal-trigger\" href=\"#modal_$id\">$title</a><br/>";
            }
        }

        // All Buildings end with a '<hr/>' except for the last building so we add this manually.
        self::$buildings[count(self::$buildings)] .= '<hr/>';

        return implode('', $newParts);
    }

    /**
     * @param string $basePart
     *
     * @return array
     */
    private static function appendToBuildings($basePart)
    {
        $basePart  = preg_replace("/<font size=\"3\">([0-9]+)<\/font>/", "##START##$0", $basePart);
        $basePart  = str_replace(array('<b><i>', '</i></b>'), '', $basePart);
        $basePart  = str_replace('</i><b>', '</i>', $basePart);
        $buildings = preg_split("/##START##/", $basePart);
        $newParts  = array();
        foreach ($buildings as $building) {
            if (preg_match("/<font size=\"3\">([0-9]+)<\/font>/", $building, $ids)
                && preg_match("/-<b>(.*?)<\/b>/", $building, $titles)
                && preg_match("/\[(.*?)\] <b>(.*?):<\/b>/", $building, $info)
            ) {
                $id         = $ids[1];
                $title      = $titles[1];
                $profession = $info[2];
                $info       = $info[1];

                $file = new DOMDocument();
                libxml_use_internal_errors(true);
                $file->loadHTML($building);
                $firstHR              = trim($file->saveHTML($file->getElementsByTagName('hr')->item(0)));
                $htmlParts            = explode($firstHR, $building);
                $htmlParts[0]         = "<h3><b>$profession</b> [$info]</h3>";
                $building             = trim(implode('<hr/>', $htmlParts));
                self::$buildings[$id] = str_replace("<h1>Building $id</h1>", "<h1>$title</h1>", self::$buildings[$id] . $building);
                $newParts[]           = "<a class=\"modal-trigger\" href=\"#modal_$id\">$title (Building $id)</a><br/>";
            }
        }
        return implode('', $newParts);
    }

    /**
     * This function parses the NPCs out of the building formats them and puts them back in in the new format.
     *
     * @param string $building
     * @param int    $buildingID
     *
     * @return string
     */
    private static function parseNPCs($building, $buildingID)
    {
        $file = new DOMDocument();
        libxml_use_internal_errors(true);
        $file->loadHTML(utf8_decode($building));
        $html = self::cleanCode($file->saveHTML());
        if (preg_match("/<h1>(.*?)<\/h1>/", $html, $title)) {
            $title = $title[0];
        }
        if (strpos($building, 'This building is empty') !== false) {
            return self::cleanCode("$title <p>This building is empty.</p>");
        }

        if (preg_match("/<font size=\"2\">-<b>(.*?)<\/font>/", $html, $owner)) {
            $html  = str_replace($owner[0], '###OWNER_PLACEHOLDER###', $html);
            $owner = self::parseNPC($owner[0], $buildingID);
            if (preg_match("/<font size=\"2\">--<b>(.*?)<\/font>/", $html, $spouse)) {
                $html   = str_replace($spouse[0], '###SPOUSE_PLACEHOLDER###', $html);
                $spouse = self::parseNPC($spouse[0], $buildingID);
                self::updateNPC($owner, 'spouse', $spouse);
            }
            if (preg_match_all("/<font size=\"2\">---<b>(.*?)<\/font>/", $html, $children)) {
                for ($i = 0; $i < count($children[0]); $i++) {
                    $html  = str_replace($children[0][$i], '###CHILD_' . $i . '_PLACEHOLDER###', $html);
                    $child = self::parseNPC($children[0][$i], $buildingID);
                    self::updateNPC($owner, 'child', $child);
                }
            }
            if (preg_match("/<h3>(.*?)<\/h3>/", $html, $other)) {
                $html = preg_replace('/###(.*)###/', self::npcToHTML($owner, true, '', true), $html);
                if (preg_match("/\[(.*?)\]/", $html, $professionInfo)) {
                    if (self::$createPosts) {
                        self::updateNPC($owner, 'profession_info', $professionInfo[1]);
                    }
                }
                if (preg_match("/<b>(.*?)<\/b>/", $html, $profession)) {
                    if (self::$createPosts) {
                        self::updateNPC($owner, 'profession', $profession[1]);
                    }
                }
            } else {
                $html = preg_replace('/###(.*)###/', self::npcToHTML($owner, true, '', false), $html);
                $html = str_replace('<hr>', '', $html);
            }
        } elseif (preg_match("/<font size=\"2\">(.*?)<\/font>/", $html, $owner)) {
            $owner          = $owner[0];
            $professionInfo = 0;
            $profession     = '';
            if (preg_match("/ \[(.*?)\]/", $owner, $professionInfo)) {
                $owner          = str_replace($professionInfo[0], '', $owner);
                $professionInfo = $professionInfo[1];
            }
            if (preg_match("/ <b>(.*?)<\/b> /", $owner, $profession)) {
                $owner      = str_replace($profession[0], '-', $owner);
                $profession = $profession[1];
            }
            $owner = self::parseNPC($owner, $buildingID);
            self::updateNPC($owner, 'profession', $profession);
            self::updateNPC($owner, 'profession_info', $professionInfo);
        }

        if (self::$createPosts) {
            wp_insert_post(
                array(
                    'post_title'   => $title,
                    'post_content' => self::finalizePart($html),
                    'post_type'    => 'buildings',
                    'post_status'  => 'publish',
                )
            );
        }

        return $html;
    }

    /**
     * @param string $npcHTML
     * @param int    $buildingID
     *
     * @return array|int the NPC (or its ID)
     */
    private static function parseNPC($npcHTML, $buildingID)
    {
        $npc = array(
            'name'        => '',
            'height'      => '',
            'weight'      => '',
            'description' => '',
            'spouse'      => '',
            'children'    => array(),
            'clothing'    => array(),
            'possession'  => array(),
            'building'    => $buildingID,
        );
        if (preg_match("/<font size=\"2\">-{1,}<b>(.*?):<\/b>/", $npcHTML, $name)) {
            $npcHTML     = str_replace($name[0], '', $npcHTML);
            $npc['name'] = $name[1];
        }
        if (preg_match("/\[<b>HGT:<\/b>(.*?)<b>WGT:<\/b>(.*?)\]/", $npcHTML, $physique)) {
            $npcHTML = str_replace($physique[0], '', $npcHTML);
            $height  = 0;
            $weight  = 0;
            if (preg_match("/(.*?)ft/", $physique[1], $feet)) {
                $height += intval($feet[1]) * 30.48;
            }
            if (preg_match("/, (.*?)in/", $physique[1], $inches)) {
                $height += intval($inches[1]) * 2.54;
            }
            if (preg_match("/(.*?)lbs/", $physique[2], $pounds)) {
                $weight = intval($pounds[1]) * 0.453592;
            }
            $npc['height'] = intval(round($height, 0));
            $npc['weight'] = intval(round($weight, 0));
        }
        if (preg_match("/<b>DRESSEDIN:<\/b>(.*?)\./", $npcHTML, $clothing)) {
            $npcHTML         = str_replace($clothing[0], '', $npcHTML);
            $npc['clothing'] = explode(', ', $clothing[1]);
            foreach ($npc['clothing'] as &$item) {
                if (mp_starts_with(trim($item), 'and')) {
                    $item = substr(trim($item), 3);
                }
                $item = ucfirst(trim($item));
            }
        }
        if (preg_match("/<b>POSSESSIONS:<\/b>(.*?)\./", $npcHTML, $possession)) {
            $npcHTML           = str_replace($possession[0], '', $npcHTML);
            $npc['possession'] = explode(', ', $possession[1]);
            foreach ($npc['possession'] as &$item) {
                if (mp_starts_with(trim($item), 'and')) {
                    $item = substr(trim($item), 3);
                }
                $item = ucfirst(trim($item));
            }
        }

        $description = trim(str_replace(array('</font>', '<hr color="#C0C0C0" size="1">'), '', $npcHTML));
        if (self::$createPosts) {
            $npcID = wp_insert_post(
                array(
                    'post_title'   => $npc['name'],
                    'post_content' => self::finalizePart($description),
                    'post_type'    => 'npcs',
                    'post_status'  => 'publish',
                )
            );
            foreach ($npc as $key => $value) {
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                update_post_meta($npcID, $key, $value);
            }
        } else {
            $npc['description'] = $description;
            $npcID              = count(self::$npcs);
        }
        self::$npcs[$npcID] = $npc;
        return $npcID;
    }

    /**
     * This function updates an NPC based on if the NPC is created as a post or if it is saved as an array.
     *
     * @param int|array $npc if createPost = true it will be an int otherwise it will be the array of NPCs.
     * @param string    $key
     * @param mixed     $value
     */
    private static function updateNPC(&$npc, $key, $value)
    {
        if (self::$createPosts) {
            if ($key == 'spouse') {
                self::updateFamilyLinks($npc, 0, $value);
            } elseif ($key == 'child') {
                self::updateFamilyLinks($npc, 1, $value);
            } else {
                update_post_meta($npc, $key, $value);
            }
        } else {
            if ($key == 'child') {
                self::$npcs[$npc]['children'][] = $value;
            } else {
                self::$npcs[$npc][$key] = $value;
            }
        }
    }

    /**
     * This function adds a family link to an NPC (if createPosts is true).
     *
     * @param int $npc
     * @param int $linkType 0 for spouse and 1 for child
     * @param int $npcID
     */
    private static function updateFamilyLinks($npc, $linkType, $npcID)
    {
        if (!self::$createPosts) {
            return;
        }
        $familyLinks = get_post_meta($npc, 'family_links', true);
        if (!is_array($familyLinks)) {
            $familyLinks = array();
        }
        $familyLinks[] = array('link_type' => $linkType, 'npc_id' => $npcID);
        update_post_meta($npc, 'family_links', $familyLinks);
    }

    /**
     * @param int    $npcID            if createPost = true it will be an int otherwise it will be the array of NPCs.
     * @param bool   $withFamily       set to false if you don't want to show the spouse and or children if any.
     * @param string $familyDefinition can be set to append for example ' (spouse)' to the name.
     * @param bool   $folded
     *
     * @return string with either the HTML of the NPC or a TAG for a WordPress post to include the NPC.
     */
    private static function npcToHTML($npcID, $withFamily = true, $familyDefinition = '', $folded = false)
    {
        if (self::$createPosts) {
            return "[npc-$npcID]";
        }
        $npc  = self::$npcs[$npcID];
        $html = $folded ? '<ul class="collapsible" data-collapsible="accordion">' : '';
        $html .= self::singleNPCToHTML($npcID, $familyDefinition, $folded);
        if ($withFamily) {
            if (!empty($npc['spouse'])) {
                $html .= self::singleNPCToHTML($npc['spouse'], ' (spouse)' . $familyDefinition, $folded);
            }
            foreach ($npc['children'] as $child) {
                $html .= self::singleNPCToHTML($child, ' (child)', $folded);
            }
        }
        $html .= $folded ? '</ul>' : '';
        return self::cleanCode($html);
    }

    private static function singleNPCToHTML($npcID, $familyDefinition, $folded)
    {
        if (self::$createPosts) {
            return $folded ? "[npc-$npcID-li]" : "[npc-$npcID]";
        }
        $npc         = self::$npcs[$npcID];
        $title       = $npc['name'] . $familyDefinition;
        $height      = $npc['height'];
        $weight      = $npc['weight'];
        $description = $npc['description'];
        $wearing     = implode(', ', $npc['clothing']);
        $profession  = implode(', ', $npc['possession']);

        if ($folded) {
            $html = '<li>';
            $html .= '<div class="collapsible-header">';
            $html .= $title;
            $html .= '</div>';
            $html .= '<div class="collapsible-body">';
            $html .= '<p>';
            $html .= '<b>Height:</b> ' . $height . ' <b>Weight:</b> ' . $weight . '<br/>';
            $html .= $description . '<br/>';
            $html .= '<b>Wearing:</b> ' . $wearing . '<br/>';
            $html .= '<b>Possessions:</b> ' . $profession . '<br/>';
            $html .= '</p>';
            $html .= '</div>';
            $html .= '</li>';
            return $html;
        } else {
            $html = '<h3>' . $title . '</h3>';
            $html .= '<p>';
            $html .= '<b>Height:</b> ' . $height . ' <b>Weight:</b> ' . $weight . '<br/>';
            $html .= $description . '<br/>';
            $html .= '<b>Wearing:</b> ' . $wearing . '<br/>';
            $html .= '<b>Possessions:</b> ' . $profession . '<br/>';
            $html .= '</p>';
            return $html;
        }
    }
}
