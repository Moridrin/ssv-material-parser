<?php
/**
 * Created by PhpStorm.
 * User: moridrin
 * Date: 26-6-17
 * Time: 20:58
 */

namespace ssv_material_parser;

use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMText;

class BuildingParser extends Parser
{
    private static $buildings = array();
    private $buildingsFromType = array();

//    public static function parseRoyalty($basePart)
//    {
//        $parser = new BuildingParser();
//        $parser->parseBase($basePart, 'royalty');
//        $parser->parseNPCs();
//    }

    /**
     * This function parses the Map and adds links to the modals.
     *
     * @param string $basePart
     * @param string $type of the building (merchant, church, etc.).
     *
     * @return array of buildings
     */
    public static function parseBuildings($basePart, $type)
    {
        $parser = new BuildingParser();
        $parser->parseBase($basePart, $type);
        $parser->parseOwner();
        $parser->parseFamily();
        $parser->parseTable();
        $parser->parseNPCs();
        if ($type == 'houses') {
            self::$buildings = $parser->buildingsFromType;
        } else {
            foreach ($parser->buildingsFromType as $building) {
                if (isset(self::$buildings[$building['id']])) {
                    self::$buildings[$building['id']] = array_merge(self::$buildings[$building['id']], $building);
                    unset(self::$buildings[$building['id']]['html']);
                }
            }
        }
        return $parser->buildingsFromType;
    }

    /**
     * This function returns all the buildings that have been parsed.
     *
     * @return array of all the buildings
     */
    public static function getBuildings()
    {
        return self::$buildings;
    }

    /**
     * This function converts the base HTML string into an array of buildings with the id, title, gold and a DOMElement with the Building HTML.
     *
     * @param string $basePart
     * @param string $type of the building (merchant, church, etc.).
     */
    private function parseBase($basePart, $type)
    {
        $parts = explode('<hr>', $basePart);
        foreach ($parts as $part) {
            $building = array();
            if (mp_starts_with($part, '<br>')) {
                continue; // This is the description and not needed.
            }
            $part = $this->cleanCode($part);
            $file = new DOMDocument();
            libxml_use_internal_errors(true);
            $file->loadHTML($part);

            $html = $file->getElementsByTagName('font');
            if ($html->item(1)->firstChild->textContent == '-This building is empty.') {
                // This building is empty.
                continue;
            }
            $id                           = $html->item(0)->firstChild->textContent;
            $building['id']               = $id;
            $building['html']             = $html;
            $title                        = $html->item(1)->childNodes->item(1)->firstChild->textContent;
            $building['title']            = mp_ends_with($title, ':') ? "Building $id" : $title;
            $building['type']             = $type;
            $building['info']             = trim(str_replace(array('[', ']'), '', $html->item(1)->childNodes->item(2)->textContent));
            $this->buildingsFromType[$id] = $building;
        }
    }

    /**
     * This function updates all buildings and adds the owner.
     */
    private function parseOwner()
    {
        foreach ($this->buildingsFromType as &$building) {
            /** @var DOMElement $html */
            $html      = $building['html']->item(1);
            $parser    = NPCParser::getParser();
            $foundNPCs = $parser->getNPCs(array('building_id' => $building['id'], 'type' => 'owner'));
            if (empty($foundNPCs)) {
                $foundNPCs = array($parser->parseOwner($html, $building['id']));
            }
            $owner               = $foundNPCs[0];
            $owner->profession = str_replace(':', '', $html->childNodes->item(3)->textContent);
            $owner->profession = $owner->profession == 'HGT' ? '' : $owner->profession;

            $parser->updateNPC($owner);
            $building['owner'] = $owner->id;
        }
    }

    private function parseFamily()
    {
        foreach ($this->buildingsFromType as &$building) {
            $parser             = NPCParser::getParser();
            $foundNPCs          = $parser->getNPCs(array('building_id' => $building['id'], 'type' => array('spouse', 'child')));
            $building['family'] = array_column($foundNPCs, 'id');
        }
    }

    /**
     * This function parses the products table into an array and adding it to the building.
     */
    private function parseTable()
    {
        foreach ($this->buildingsFromType as &$building) {
            /** @var DOMElement $html */
            $html  = $building['html']->item(1);
            $table = $html->getElementsByTagName('tbody')->item(0);
            if ($table == null) {
                return;
            }
            for ($i = 1; $i < $table->childNodes->length; $i++) {
                $row                    = $table->childNodes->item($i);
                $product                = array();
                $product['item']        = $row->childNodes->item(1)->firstChild->firstChild->textContent;
                $product['cost']        = $row->childNodes->item(2)->firstChild->firstChild->textContent;
                $product['stock']       = $row->childNodes->item(3)->firstChild->firstChild->textContent;
                $building['products'][] = $product;
            }
        }
    }

    /**
     * This function parses the products table into an array and adding it to the building.
     */
    private function parseNPCs()
    {
        foreach ($this->buildingsFromType as &$building) {
            if (isset($building['products'])) {
                continue; // Buildings with products are merchants and those don't have more NPCs than just the owner (and that one is already parsed).
            }
            /** @var DOMElement $html */
            $html     = $building['html']->item(1);
            $npcParts = $html->getElementsByTagName('font');
            for ($i = 0; $i < $npcParts->length; $i++) {
                $npcPart = $npcParts->item($i);
                if ($npcPart->firstChild instanceof DOMText) {
                    $this->parseSpell($building, $npcPart);
                    continue; // This is not an NPC but a spell.
                }
                $parser             = NPCParser::getParser();
                $npc                = $parser->parseBuildingNPC($npcPart, $building['id'], $building['type']);
                $building['npcs'][] = $npc->id;
            }
        }
    }

    /**
     * @param array      $building
     * @param DOMElement $html
     */
    private function parseSpell(&$building, $html)
    {
        $building['spells_cast_on'] = explode('.', $html->childNodes->item(0)->textContent)[0] . '.';
        for ($i = 1; $i < $html->childNodes->length; $i++) {
            $name = $html->childNodes->item($i)->firstChild->textContent;
            $i++;
            $cost = str_replace(',', '', explode(' ', $html->childNodes->item($i)->textContent)[2]);

            $building['spells'][] = array(
                'name' => $name,
                'cost' => $cost,
            );
        }
    }

    /**
     * @param array   $building
     * @param array[] $npcs
     * @param string  $city
     */
    public static function toWordPress(&$building, $npcs, $city)
    {
        $building['city']  = $city;
        $building['owner'] = $npcs[$building['owner']]['wp_id'];
        if (isset($building['npcs'])) {
            foreach ($building['npcs'] as &$npcID) {
                $npcID = $npcs[$npcID]['wp_id'];
            }
        }
        $title = $building['title'];
        /** @var \wpdb $wpdb */
        global $wpdb;
        $sql         = "SELECT p.ID FROM $wpdb->posts AS p";
        $keysToCheck = array('info', 'owner');
        foreach ($keysToCheck as $key) {
            $sql .= " LEFT JOIN $wpdb->postmeta AS pm_$key ON pm_$key.post_id = p.ID";
        }
        $sql .= " WHERE p.post_type = 'building' AND p.post_title = '$title'";
        foreach ($keysToCheck as $key) {
            $value = $building[$key];
            $sql   .= " AND pm_$key.meta_key = '$key' AND pm_$key.meta_value = '$value'";
        }
        /** @var \WP_Post $foundBuilding */
        $foundBuilding = $wpdb->get_row($sql);
        if ($foundBuilding) {
            $terms = wp_get_post_terms($foundBuilding->ID, 'building_category');
            if (in_array($city, array_column($terms, 'name'))) {
                //Only if the building is in the same city it is the same building.
                $building['wp_id'] = $foundBuilding->ID;
                return;
            }
        }

        $buildingType     = mp_to_title($building['type']);
        $buildingTypeTerm = term_exists($buildingType, 'building_category', 0);
        if (!$buildingTypeTerm) {
            $buildingTypeTerm = wp_insert_term($buildingType, 'building_category', array('parent' => 0));
        }

        $custom_tax = array(
            'building_category' => array(
                $buildingTypeTerm['term_taxonomy_id'],
            ),
        );

        $postID = wp_insert_post(
            array(
                'post_title'   => $building['title'],
                'post_content' => self::toHTML($building),
                'post_type'    => 'building',
                'post_status'  => 'publish',
                'tax_input'    => $custom_tax,
            )
        );
        foreach ($building as $key => $value) {
            if ($key == 'title' || $key == 'products' || $key == 'html') {
                continue;
            }
            update_post_meta($postID, $key, $value);
        }
        $building['wp_id'] = $postID;
    }

    public static function toHTML($building)
    {
        ob_start();
        if ($building['type'] == 'houses') {
            echo '[npc-owner-with-family]';
        } else {
            echo '[npc-li-owner-with-family]';
        }
        if (isset($building['npcs'])) {
            foreach ($building['npcs'] as $npcID) {
                echo "[npc-$npcID]";
            }
        }
        if (isset($building['products'])) {
            ?>
            <table class="striped responsive-table">
                <thead>
                <tr>
                    <th>Item</th>
                    <th>Cost</th>
                    <th>Stock</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($building['products'] as $product): ?>
                    <tr>
                        <td><?= $product['item'] ?></td>
                        <td><?= $product['cost'] ?></td>
                        <td><?= $product['stock'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        }
        return self::cleanCode(ob_get_clean());
    }
}