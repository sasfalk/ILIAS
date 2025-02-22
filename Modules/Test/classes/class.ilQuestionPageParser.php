<?php

/**
 * This file is part of ILIAS, a powerful learning management system
 * published by ILIAS open source e-Learning e.V.
 *
 * ILIAS is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with the
 * source code, too.
 *
 * If this is not the case or you just want to try ILIAS, you'll find
 * us at:
 * https://www.ilias.de
 * https://github.com/ILIAS-eLearning
 *
 *********************************************************************/

use ILIAS\Filesystem\Stream\Streams;

/**
 * Legacy Content Object Parser
 *
 * @author Alexander Killing <killing@leifos.de>
 * @deprecated use COPage dependency to export/import COPages instead
 */
class ilQuestionPageParser extends ilMDSaxParser
{
    /**
     * @var false
     */
    protected $in_properties;
    /**
     * @var false
     */
    protected $in_glossary_definition = false;
    protected $media_meta_cache = [];
    protected $media_meta_start = false;
    protected $pg_mapping = [];
    protected $mobs_with_int_links = [];
    protected $inside_code = false;
    protected $coType = "";
    protected $import_dir = "";
    public $tree;
    public $cnt = [];				// counts open elements
    public $current_element = [];	// store current element type
    public $learning_module = null;	// current learning module
    public $page_object = null;		// current page object
    public $lm_page_object = null;
    public $structure_objects = [];	// array of current structure objects
    public $media_object = null;
    public $current_object = null;	// at the time a LearningModule, PageObject or StructureObject
    public $lm_tree = null;
    public $pg_into_tree = [];
    public $st_into_tree = [];
    public $container = [];
    public $in_page_object = false;	// are we currently within a PageObject? true/false
    public $in_meta_data = false;		// are we currently within MetaData? true/false
    public $in_media_object = false;
    public $in_file_item = false;
    public $in_glossary = false;
    public $in_map_area = false;
    public $content_object = null;
    public $glossary_object = null;
    public $file_item = null;
    public $pages_to_parse = [];
    public $mob_mapping = [];
    public $file_item_mapping = [];
    public $subdir = "";
    public $media_item = null;		// current media item
    public $loc_type = "";			// current location type
    public $map_area = null;			// current map area
    public $link_targets = [];		// stores all objects by import id
    public $qst_mapping = [];
    public $metadata_parsing_disabled = false;
    public $in_meta_meta_data = false;
    protected $glossary_term_map = [];
    protected $log;
    protected $glossary_term = null;
    protected $mapping = null;
    protected $cur_qid = "";
    protected $glossary_definition = null;
    protected $chr_data = "";
    protected $in_media_item = false;

    public function __construct(
        ilObject $a_content_object,
        string $a_xml_file,
        string $a_subdir,
        string $a_import_dir = ""
    ) {
        global $DIC;

        $this->log = $DIC["ilLog"];
        $lng = $DIC->language();
        $tree = $DIC->repositoryTree();

        $this->log = ilLoggerFactory::getLogger('lm');

        $this->import_dir = ($a_import_dir != "")
            ? $a_import_dir
            : $a_content_object->getImportDirectory();

        parent::__construct($a_xml_file);
        $this->cnt = array();
        $this->current_element = array();
        $this->structure_objects = array();
        $this->content_object = $a_content_object;
        $this->st_into_tree = array();
        $this->pg_into_tree = array();
        $this->pages_to_parse = array();
        $this->mobs_with_int_links = array();
        $this->mob_mapping = array();
        $this->file_item_mapping = array();
        $this->pg_mapping = array();
        $this->link_targets = array();
        $this->subdir = $a_subdir;
        $this->lng = $lng;
        $this->tree = $tree;
        $this->inside_code = false;
        $this->qst_mapping = array();
        $this->coType = $this->content_object->getType();
        $this->metadata_parsing_disabled = false;

        if (($this->coType != "tst") and ($this->coType != "qpl")) {
            $this->lm_tree = new ilLMTree($this->content_object->getId());
        }
    }

    /**
     * @param resource $a_xml_parser
     */
    public function setHandlers($a_xml_parser) : void
    {
        xml_set_object($a_xml_parser, $this);
        xml_set_element_handler($a_xml_parser, 'handlerBeginTag', 'handlerEndTag');
        xml_set_character_data_handler($a_xml_parser, 'handlerCharacterData');
    }

    public function setImportMapping(ilImportMapping $mapping = null) : void
    {
        $this->mapping = $mapping;
    }

    public function startParsing() : void
    {
        $this->log->debug("start");

        //echo "<b>start parsing</b><br>";
        parent::startParsing();
        //echo "<b>storeTree</b><br>";
        $this->storeTree();
        //echo "<b>processPagesToParse</b><br>";
        $this->processPagesToParse();
        //echo "<b>copyMobFiles</b><br>";
        $this->copyMobFiles();
        //echo "<b>copyFileItems</b><br>";
        $this->copyFileItems();
        //echo "<br>END Parsing"; exit;
    }

    /**
     * insert StructureObjects and PageObjects into tree
     */
    public function storeTree() : void
    {
        $ilLog = $this->log;

        foreach ($this->st_into_tree as $st) {
            $this->lm_tree->insertNode($st["id"], $st["parent"]);
            if (is_array($this->pg_into_tree[$st["id"]])) {
                foreach ($this->pg_into_tree[$st["id"]] as $pg) {
                    $pg_id = 0;
                    switch ($pg["type"]) {
                        case "pg_alias":
                            if ($this->pg_mapping[$pg["id"]] == "") {
                                $ilLog->write("LM Import: No PageObject for PageAlias " .
                                              $pg["id"] . " found! (Please update export installation to ILIAS 3.3.0)");

                                // Jump two levels up. First level is switch
                                continue 2;
                            }
                            $pg_id = $this->pg_mapping[$pg["id"]];
                            break;

                        case "pg":
                            $pg_id = $pg["id"];
                            break;
                    }
                    if (!$this->lm_tree->isInTree($pg_id)) {
                        $this->lm_tree->insertNode($pg_id, $st["id"]);
                    }
                }
            }
        }
    }

    /**
     * parse pages that contain files, mobs and/or internal links
     */
    public function processPagesToParse() : void
    {
        // outgoin internal links
        foreach ($this->pages_to_parse as $page_id) {
            $page_arr = explode(":", $page_id);
            $page_obj = null;
            //echo "<br>resolve:".$this->content_object->getType().":".$page_id; flush();
            switch ($page_arr[0]) {
                case "lm":
                    switch ($this->content_object->getType()) {
                        case "lm":
                            $page_obj = new ilLMPage($page_arr[1]);
                            break;

                        default:
                            die("Unknown content type " . $this->content_object->getType());
                    }

                    break;

                case "gdf":
                    $page_obj = new ilGlossaryDefPage($page_arr[1]);
                    break;

                case "qpl":
                    $page_obj = new ilAssQuestionPage($page_arr[1]);
                    break;
            }
            $page_obj->buildDom();
            $page_obj->resolveIntLinks();
            $page_obj->resolveIIMMediaAliases($this->mob_mapping);
            if ($this->coType == "lm") {
                $page_obj->resolveQuestionReferences($this->qst_mapping);
            }
            $page_obj->update(false);

            if ($page_arr[0] == "gdf") {
                $def = new ilGlossaryDefinition($page_arr[1]);
                $def->updateShortText();
            }

            unset($page_obj);
        }

        //echo "<br><b>map area internal links</b>"; flush();
        // outgoins map area (mob) internal links
        foreach ($this->mobs_with_int_links as $mob_id) {
            ilMediaItem::_resolveMapAreaLinks($mob_id);
        }

        //echo "<br><b>incoming interna links</b>"; flush();
        // incoming internal links
        $done = array();
        foreach ($this->link_targets as $link_target) {
            //echo "doin link target:".$link_target.":<br>";
            $link_arr = explode("_", $link_target);
            $target_inst = $link_arr[1];
            $target_type = $link_arr[2];
            $target_id = $link_arr[3];
            //echo "<br>-".$target_type."-".$target_id."-".$target_inst."-";
            $sources = ilInternalLink::_getSourcesOfTarget($target_type, $target_id, $target_inst);
            foreach ($sources as $key => $source) {
                //echo "got source:".$key.":<br>";
                if (in_array($key, $done)) {
                    continue;
                }
                $type_arr = explode(":", $source["type"]);

                // content object pages
                if ($type_arr[1] == "pg") {
                    if (ilPageObject::_exists($type_arr[0], $source["id"])) {
                        $page_object = ilPageObjectFactory::getInstance($type_arr[0], $source["id"]);
                        $page_object->buildDom();
                        $page_object->resolveIntLinks();
                        $page_object->update();
                        unset($page_object);
                    }
                }

                // eventually correct links in questions to learning modules
                if ($type_arr[0] == "qst") {
                    assQuestion::_resolveIntLinks($source["id"]);
                }
                // eventually correct links in survey questions to learning modules
                if ($type_arr[0] == "sqst") {
                    SurveyQuestion::_resolveIntLinks($source["id"]);
                }
                $done[$key] = $key;
            }
        }
    }


    /**
     * copy multimedia object files from import zip file to mob directory
     */
    public function copyMobFiles() : void
    {
        $imp_dir = $this->import_dir;
        foreach ($this->mob_mapping as $origin_id => $mob_id) {
            if (empty($origin_id)) {
                continue;
            }

            $obj_dir = $origin_id;
            $source_dir = $imp_dir . "/" . $this->subdir . "/objects/" . $obj_dir;
            $target_dir = ilUtil::getWebspaceDir() . "/mobs/mm_" . $mob_id;

            if (is_dir($source_dir)) {
                ilUtil::makeDir($target_dir);

                if (is_dir($target_dir)) {
                    ilLoggerFactory::getLogger("mob")->debug("s:-$source_dir-,t:-$target_dir-");
                    ilUtil::rCopy(realpath($source_dir), realpath($target_dir));
                }
            }
        }
    }

    /**
     * copy files of file items
     */
    public function copyFileItems() : void
    {
        $imp_dir = $this->import_dir;
        foreach ($this->file_item_mapping as $origin_id => $file_id) {
            if (empty($origin_id)) {
                continue;
            }
            $obj_dir = $origin_id;
            $source_dir = $imp_dir . "/" . $this->subdir . "/objects/" . $obj_dir;

            $file_obj = new ilObjFile($file_id, false);
            if (is_dir($source_dir)) {
                $files = scandir($source_dir, SCANDIR_SORT_DESCENDING);
                if ($files !== false && $files !== [] && is_file($source_dir . '/' . $files[0])) {
                    $file = fopen($source_dir . '/' . $files[0], 'rb');
                    $file_stream = Streams::ofResource($file);
                    $file_obj->appendStream($file_stream, $files[0]);
                }
            }
            $file_obj->update();
        }
    }

    /**
                 * set question import ident to pool/test question id mapping
                 * @param mixed[] $a_map
                 */
    public function setQuestionMapping(array $a_map) : void
    {
        $this->qst_mapping = $a_map;
    }

    public function beginElement(string $a_name) : void
    {
        if (!isset($this->status["$a_name"])) {
            $this->cnt[$a_name] = 1;
        } else {
            $this->cnt[$a_name]++;
        }
        $this->current_element[count($this->current_element)] = $a_name;
    }

    public function endElement(string $a_name) : void
    {
        $this->cnt[$a_name]--;
        unset($this->current_element[count($this->current_element) - 1]);
    }

    public function getCurrentElement() : string
    {
        return ($this->current_element[count($this->current_element) - 1] ?? "");
    }

    public function getOpenCount(string $a_name) : int
    {
        if (isset($this->cnt[$a_name])) {
            return $this->cnt[$a_name];
        }
        return 0;
    }

    public function buildTag(
        string $type,
        string $name,
        array $attr = []
    ) : string {
        $tag = "<";

        if ($type == "end") {
            $tag .= "/";
        }

        $tag .= $name;

        if (is_array($attr)) {
            foreach ($attr as $k => $v) {
                $tag .= " " . $k . "=\"$v\"";
            }
        }

        $tag .= ">";

        return $tag;
    }

    public function handlerBeginTag($a_xml_parser, $a_name, $a_attribs) : void
    {
        switch ($a_name) {
            case "ContentObject":
                $this->current_object = $this->content_object;
                if ($a_attribs["Type"] == "Glossary") {
                    $this->glossary_object = $this->content_object;
                }
                break;

            case "StructureObject":
                /** @var ilObjLearningModule $lm $ */
                $lm = $this->content_object;
                $this->structure_objects[count($this->structure_objects)]
                    = new ilStructureObject($lm);
                $this->current_object = $this->structure_objects[count($this->structure_objects) - 1];
                $this->current_object->setLMId($this->content_object->getId());
                // new meta data handling: we create the structure
                // object already here, this should also create a
                // md entry
                $this->current_object->create(true);
                break;

            case "PageObject":
                $this->in_page_object = true;
                $this->cur_qid = "";
                if (($this->coType != "tst") and ($this->coType != "qpl")) {
                    /** @var ilObjLearningModule $lm $ */
                    $lm = $this->content_object;
                    $this->lm_page_object = new ilLMPageObject($lm);
                    $this->page_object = new ilLMPage();
                    $this->lm_page_object->setLMId($this->content_object->getId());
                    $this->lm_page_object->assignPageObject($this->page_object);
                    $this->current_object = $this->lm_page_object;
                } else {
                    $this->page_object = new ilAssQuestionPage();
                }
                break;

            case "PageAlias":
                throw new ilLMException("Page Alias not supported.");
                /*
                if (($this->coType != "tst") and ($this->coType != "qpl")) {
                    $this->lm_page_object->setAlias(true);
                    $this->lm_page_object->setOriginID($a_attribs["OriginId"]);
                }
                break;*/

            case "MediaObject":
            case "InteractiveImage":
                if ($a_name == "MediaObject") {
                    $this->in_media_object = true;
                }
                $this->media_meta_start = true;
                $this->media_meta_cache = array();
                $this->media_object = new ilObjMediaObject();
                break;

            case "MediaAlias":
                $this->media_object->setAlias(true);
                $this->media_object->setImportId($a_attribs["OriginId"]);
                if (is_object($this->page_object)) {
                    $this->page_object->needsImportParsing(true);
                }
                break;

            case "MediaItem":
            case "MediaAliasItem":
                $this->in_media_item = true;
                $this->media_item = new ilMediaItem();
                $this->media_item->setPurpose($a_attribs["Purpose"]);
                break;

            case "Layout":
                if (is_object($this->media_object) && $this->in_media_object) {
                    $this->media_item->setWidth($a_attribs["Width"] ?? '');
                    $this->media_item->setHeight($a_attribs["Height"] ?? '');
                    $this->media_item->setHAlign($a_attribs["HorizontalAlign"] ?? '');
                }
                break;

            case "Parameter":
                if (is_object($this->media_object) && $this->in_media_object) {
                    $this->media_item->setParameter($a_attribs["Name"], $a_attribs["Value"]);
                }
                break;

            case "MapArea":
                $this->in_map_area = true;
                $this->map_area = new ilMapArea();
                $this->map_area->setShape($a_attribs["Shape"]);
                $this->map_area->setCoords($a_attribs["Coords"]);
                $this->map_area->setHighlightMode($a_attribs["HighlightMode"]);
                $this->map_area->setHighlightClass($a_attribs["HighlightClass"]);
                break;

            case "Glossary":
                $this->in_glossary = true;
                if ($this->content_object->getType() != "glo") {
                    $this->glossary_object = new ilObjGlossary();
                    $this->glossary_object->setTitle("");
                    $this->glossary_object->setDescription("");
                    $this->glossary_object->create(true);
                    $this->glossary_object->createReference();
                    $parent = $this->tree->getParentNodeData($this->content_object->getRefId());
                    $this->glossary_object->putInTree($parent["child"]);
                    $this->glossary_object->setPermissions($parent["child"]);
                }
                $this->current_object = $this->glossary_object;
                break;

            case "GlossaryItem":
                $this->glossary_term = new ilGlossaryTerm();
                $this->glossary_term->setGlossaryId($this->glossary_object->getId());
                $this->glossary_term->setLanguage($a_attribs["Language"]);
                $this->glossary_term->setImportId($a_attribs["Id"]);
                $this->link_targets[$a_attribs["Id"]] = $a_attribs["Id"];
                break;

            case "Definition":
                $this->in_glossary_definition = true;
                $this->glossary_definition = new ilGlossaryDefinition();
                $this->page_object = new ilGlossaryDefPage();
                $this->page_object->setParentId($this->glossary_term->getGlossaryId());
                $this->glossary_definition->setTermId($this->glossary_term->getId());
                $this->glossary_definition->assignPageObject($this->page_object);
                $this->current_object = $this->glossary_definition;
                $this->glossary_definition->create(true);
                // see bug #12465, we need to clear xml after creation, since it will be <PageObject></PageObject>
                // otherwise, and appendXML calls will lead to "<PageObject></PageObject><PageObject>....</PageObject>"
                $this->page_object->setXMLContent("");
                break;

            case "FileItem":
                $this->in_file_item = true;
                $this->file_item = new ilObjFile();
                $this->file_item->setTitle("dummy");
                $this->file_item->setMode("filelist");
                if (is_object($this->page_object)) {
                    $this->page_object->needsImportParsing(true);
                }
                break;

            case "Paragraph":
                if ($a_attribs["Characteristic"] == "Code") {
                    $this->inside_code = true;
                }
                break;

            case "Properties":
                $this->in_properties = true;
                break;

            case "Property":
                if ($this->content_object->getType() == "lm") {
                    switch ($a_attribs["Name"]) {
                        case "Layout":
                            $this->content_object->setLayout($a_attribs["Value"]);
                            break;

                        case "PageHeader":
                            $this->content_object->setPageHeader($a_attribs["Value"]);
                            break;

                        case "TOCMode":
                            $this->content_object->setTOCMode($a_attribs["Value"]);
                            break;

                        case "ActiveLMMenu":
                            $this->content_object->setActiveLMMenu(
                                ilUtil::yn2tf($a_attribs["Value"])
                            );
                            break;

                        case "ActiveNumbering":
                            $this->content_object->setActiveNumbering(
                                ilUtil::yn2tf($a_attribs["Value"])
                            );
                            break;

                        case "ActiveTOC":
                            $this->content_object->setActiveTOC(
                                ilUtil::yn2tf($a_attribs["Value"])
                            );
                            break;

                        case "ActivePrintView":
                            $this->content_object->setActivePrintView(
                                ilUtil::yn2tf($a_attribs["Value"])
                            );
                            break;

                        case "CleanFrames":
                            $this->content_object->setCleanFrames(
                                ilUtil::yn2tf($a_attribs["Value"])
                            );
                            break;

                        case "PublicNotes":
                            $this->content_object->setPublicNotes(
                                ilUtil::yn2tf($a_attribs["Value"])
                            );
                            break;

                        case "HistoryUserComments":
                            $this->content_object->setHistoryUserComments(
                                ilUtil::yn2tf($a_attribs["Value"])
                            );
                            break;

                        case "Rating":
                            $this->content_object->setRating(
                                ilUtil::yn2tf($a_attribs["Value"])
                            );
                            break;

                        case "RatingPages":
                            $this->content_object->setRatingPages(
                                ilUtil::yn2tf($a_attribs["Value"])
                            );
                            break;

                        case "HeaderPage":
                            if ($a_attribs["Value"] != "") {
                                if ($this->pg_mapping[$a_attribs["Value"]] > 0) {
                                    $this->content_object->setHeaderPage(
                                        $this->pg_mapping[$a_attribs["Value"]]
                                    );
                                }
                            }
                            break;

                        case "FooterPage":
                            if ($a_attribs["Value"] != "") {
                                if ($this->pg_mapping[$a_attribs["Value"]] > 0) {
                                    $this->content_object->setFooterPage(
                                        $this->pg_mapping[$a_attribs["Value"]]
                                    );
                                }
                            }
                            break;

                        case "LayoutPerPage":
                            $this->content_object->setLayoutPerPage($a_attribs["Value"]);
                            break;

                        case "ProgressIcons":
                            $this->content_object->setProgressIcons($a_attribs["Value"]);
                            break;

                        case "StoreTries":
                            $this->content_object->setStoreTries($a_attribs["Value"]);
                            break;

                        case "RestrictForwardNavigation":
                            $this->content_object->setRestrictForwardNavigation($a_attribs["Value"]);
                            break;

                        case "DisableDefaultFeedback":
                            $this->content_object->setDisableDefaultFeedback($a_attribs["Value"]);
                            break;

                    }
                }
                break;

                ////////////////////////////////////////////////
                /// Meta Data Section
                ////////////////////////////////////////////////
            case "MetaData":
                $this->in_meta_data = true;
                // media obejct meta data handling
                // is done in the "Identifier" begin tag processing
                // the rest is done here
                if (!$this->in_media_object) {
                    if (($this->coType != "tst") and ($this->coType != "qpl")) {
                        // type pg/st
                        if ($this->current_object->getType() == "st" ||
                            $this->current_object->getType() == "pg") {
                            // late creation of page object
                            if ($this->current_object->getType() == "pg") {
                                $this->lm_page_object->create(true);
                            }
                            $this->md = new ilMD(
                                $this->content_object->getId(),
                                $this->current_object->getId(),
                                $this->current_object->getType()
                            );
                        }
                        // type gdf
                        elseif ($this->current_object->getType() == "gdf") {
                            $this->md = new ilMD(
                                $this->glossary_object->getId(),
                                $this->current_object->getId(),
                                $this->current_object->getType()
                            );
                        }
                        // type lm, dbk, glo
                        else {
                            if ($this->processMeta()) {
                                $this->md = new ilMD(
                                    $this->current_object->getId(),
                                    0,
                                    $this->current_object->getType()
                                );
                            }
                        }
                    } else {
                        // type qpl or tst
                        $this->md = new ilMD(
                            $this->content_object->getId(),
                            0,
                            $this->current_object->getType()
                        );
                        if ($this->md->getGeneral() != false) {
                            $this->metadata_parsing_disabled = true;
                            $this->enableMDParsing(false);
                        }
                    }
                }
                break;

                // Identifier
            case "Identifier":

                // begin-patch optes_lok_export
                if ($this->in_meta_data && $this->current_object instanceof ilStructureObject) {
                    if ($this->mapping instanceof ilImportMapping) {
                        $import_id_parsed = ilUtil::parseImportId($a_attribs['Entry']);
                        if ($import_id_parsed['type'] == 'st') {
                            $this->mapping->addMapping(
                                'Modules/LearningModule',
                                'lm_tree',
                                $import_id_parsed['id'],
                                $this->current_object->getId()
                            );
                        }
                    }
                }
                // end-patch optes_lok_export

                // please note: Meta-Metadata and MetaData are different tags!
                if (!$this->in_meta_meta_data) {
                    if ($this->in_meta_data && !$this->in_glossary_definition) {
                        if (!$this->in_media_object) {
                            $this->current_object->setImportId($a_attribs["Entry"]);
                        }
                        $this->link_targets[$a_attribs["Entry"]] = $a_attribs["Entry"];
                    }
                    if ($this->in_file_item) {
                        if (!isset($this->file_item_mapping[$a_attribs["Entry"]])
                            || $this->file_item_mapping[$a_attribs["Entry"]] === "") {
                            $this->file_item->create();
                            $this->file_item->setImportId($a_attribs["Entry"]);
                            $this->file_item_mapping[$a_attribs["Entry"]] = $this->file_item->getId();
                        }
                    }
                    if ($this->in_meta_data && $this->in_media_object) {
                        //echo "looking for -".$a_attribs["Entry"]."-<br>";

                        $mob_id = $this->mob_mapping[$a_attribs["Entry"]];

                        // within learning module import, usually a media object
                        // has already been created with a media alias tag
                        if ($mob_id > 0) {
                            $this->media_object = new ilObjMediaObject($mob_id);
                        } else {	// in glossaries the media objects precede the definitions
                            // so we don't have an object already
                            $this->media_object = new ilObjMediaObject();
                            $this->media_object->create(true, false);
                            $this->mob_mapping[$a_attribs["Entry"]]
                                = $this->media_object->getId();
                        }
                        $this->media_object->setImportId($a_attribs["Entry"]);
                        $this->md = new ilMD(
                            0,
                            $this->media_object->getId(),
                            "mob"
                        );
                        $this->emptyMediaMetaCache($a_xml_parser);
                    }
                }
                break;

            case "Meta-Metadata":
                $this->in_meta_meta_data = true;
                break;

                // Internal Link
            case "IntLink":
                if (is_object($this->page_object)) {
                    $this->page_object->setContainsIntLink(true);
                }
                if ($this->in_map_area) {
                    //echo "intlink:maparea:<br>";
                    $this->map_area->setLinkType(IL_INT_LINK);
                    $this->map_area->setTarget($a_attribs["Target"]);
                    $this->map_area->setType($a_attribs["Type"]);
                    $this->map_area->setTargetFrame($a_attribs["TargetFrame"]);
                    if (is_object($this->media_object)) {
                        //echo ":setContainsLink:<br>";
                        $this->media_object->setContainsIntLink(true);
                    }
                }
                break;

                // External Link
            case "ExtLink":
                if ($this->in_map_area) {
                    $this->map_area->setLinkType(IL_EXT_LINK);
                    $this->map_area->setHref($a_attribs["Href"]);
                    $this->map_area->setExtTitle($a_attribs["Title"]);
                }
                break;

                // Question
            case "Question":
                $this->cur_qid = $a_attribs["QRef"];
                $this->page_object->setContainsQuestion(true);
                break;

            case "Location":
                $this->loc_type = $a_attribs["Type"];
                break;

        }
        $this->beginElement($a_name);

        // append content to page xml content
        if (($this->in_page_object || $this->in_glossary_definition)
            && !$this->in_meta_data && !$this->in_media_object) {
            if ($a_name == "Definition") {
                $app_name = "PageObject";
                $app_attribs = array();
            } else {
                $app_name = $a_name;
                $app_attribs = $a_attribs;
            }

            // change identifier entry of file items to new local file id
            if ($this->in_file_item && $app_name == "Identifier") {
                $app_attribs["Entry"] = "il__file_" . $this->file_item_mapping[$a_attribs["Entry"]];
                //$app_attribs["Entry"] = "il__file_".$this->file_item->getId();
            }

            $this->page_object->appendXMLContent($this->buildTag("start", $app_name, $app_attribs));
            //echo "&nbsp;&nbsp;after append, xml:".$this->page_object->getXMLContent().":<br>";
        }


        // call meta data handler
        if ($this->in_meta_data && $this->processMeta()) {
            // cache beginning of meta data within media object tags
            // (we need to know the id at the begin of meta elements within
            // media objects, after the "Identifier" tag has been processed
            // we send the cached data to the meta xml handler)
            if ($this->in_media_object && $this->media_meta_start) {
                $this->media_meta_cache[] =
                    array("type" => "handlerBeginTag", "par1" => $a_name, "par2" => $a_attribs);
            } else {
                if ($a_name == "Identifier") {
                    if (!$this->in_media_object) {
                        $a_attribs["Entry"] = "il__" . $this->current_object->getType() .
                            "_" . $this->current_object->getId();
                    } else {
                        $a_attribs["Entry"] = "il__mob" .
                            "_" . $this->media_object->getId();
                    }
                    $a_attribs["Catalog"] = "ILIAS";
                }

                parent::handlerBeginTag($a_xml_parser, $a_name, $a_attribs);
            }
        }
    }

    public function processMeta() : bool
    {
        // do not process second meta block in (ilias3) glossaries
        // which comes right after the "Glossary" tag
        if ($this->content_object->getType() == "glo" &&
            $this->in_glossary && !$this->in_media_object
            && !$this->in_glossary_definition) {
            return false;
        }

        return true;
    }


    public function handlerEndTag($a_xml_parser, $a_name) : void
    {
        // call meta data handler
        if ($this->in_meta_data && $this->processMeta()) {
            // cache beginning of meta data within media object tags
            // (we need to know the id, after that we send the cached data
            // to the meta xml handler)
            if ($this->in_media_object && $this->media_meta_start) {
                $this->media_meta_cache[] =
                    array("type" => "handlerEndTag", "par1" => $a_name);
            } else {
                parent::handlerEndTag($a_xml_parser, $a_name);
            }
        }

        // append content to page xml content
        if (($this->in_page_object || $this->in_glossary_definition)
            && !$this->in_meta_data && !$this->in_media_object) {
            $app_name = ($a_name == "Definition")
                ? "PageObject"
                : $a_name;
            $this->page_object->appendXMLContent($this->buildTag("end", $app_name));
        }

        switch ($a_name) {
            case "StructureObject":
                unset($this->structure_objects[count($this->structure_objects) - 1]);
                break;

            case "PageObject":

                $this->in_page_object = false;
                if (($this->coType != "tst") and ($this->coType != "qpl")) {
                    //if (!$this->lm_page_object->isAlias()) {
                    $this->page_object->updateFromXML();
                    $this->pg_mapping[$this->lm_page_object->getImportId()]
                            = $this->lm_page_object->getId();

                    if ($this->mapping instanceof ilImportMapping) {
                        $import_id_parsed = ilUtil::parseImportId($this->lm_page_object->getImportId());
                        if ($import_id_parsed['type'] == 'pg') {
                            $this->mapping->addMapping(
                                'Modules/LearningModule',
                                'pg',
                                $import_id_parsed['id'],
                                $this->lm_page_object->getId()
                            );
                        }
                    }

                    // collect pages with internal links
                    if ($this->page_object->containsIntLink()) {
                        $this->pages_to_parse["lm:" . $this->page_object->getId()] = "lm:" . $this->page_object->getId();
                    }

                    // collect pages with mobs or files
                    if ($this->page_object->needsImportParsing()) {
                        $this->pages_to_parse["lm:" . $this->page_object->getId()] = "lm:" . $this->page_object->getId();
                    }

                    // collect pages with questions
                    if ($this->page_object->getContainsQuestion()) {
                        $this->pages_to_parse["lm:" . $this->page_object->getId()] = "lm:" . $this->page_object->getId();
                    }
                    //}
                } else {
                    $xml = $this->page_object->getXMLContent();
                    if ($this->cur_qid != "") {
                        $ids = $this->qst_mapping[$this->cur_qid] ?? ['pool' => 0, 'test' => 0];
                        if ($ids["pool"] > 0) {
                            // question pool question
                            $page = new ilAssQuestionPage($ids["pool"]);
                            $xmlcontent = str_replace(
                                $this->cur_qid,
                                "il__qst_" . $ids["pool"],
                                $xml
                            );
                            $page->setXMLContent($xmlcontent);
                            $page->updateFromXML();
                            if ($this->page_object->needsImportParsing()) {
                                $this->pages_to_parse["qpl:" . $page->getId()] = "qpl:" . $page->getId();
                            }
                            unset($page);
                        }
                        if ($ids["test"] > 0) {
                            // test question
                            $page = new ilAssQuestionPage($ids["test"]);
                            $xmlcontent = str_replace(
                                $this->cur_qid,
                                "il__qst_" . $ids["test"],
                                $xml
                            );
                            $page->setXMLContent($xmlcontent);
                            $page->updateFromXML();
                            if ($this->page_object->needsImportParsing()) {
                                $this->pages_to_parse["qpl:" . $page->getId()] = "qpl:" . $page->getId();
                            }
                            unset($page);
                        }
                    }
                }

                // if we are within a structure object: put page in tree
                $cnt = count($this->structure_objects);
                if ($cnt > 0) {
                    $parent_id = $this->structure_objects[$cnt - 1]->getId();
                    //if ($this->lm_page_object->isAlias()) {
                    //    $this->pg_into_tree[$parent_id][] = array("type" => "pg_alias", "id" => $this->lm_page_object->getOriginId());
                    //} else {
                    $this->pg_into_tree[$parent_id][] = array("type" => "pg", "id" => $this->lm_page_object->getId());
                    //}
                }

                unset($this->page_object);
                unset($this->lm_page_object);
                unset($this->container[count($this->container) - 1]);
                break;

            case "MediaObject":
            case "InteractiveImage":
                if ($a_name == "MediaObject") {
                    $this->in_media_object = false;
                }

                if (empty($this->mob_mapping[$this->media_object->getImportId()])) {
                    // create media object
                    // media items are saves for mobs outside of
                    // pages only
                    $this->media_object->create(true, false);

                    // collect mobs with internal links
                    if ($this->media_object->containsIntLink()) {
                        //echo "got int link :".$this->media_object->getId().":<br>";
                        $this->mobs_with_int_links[] = $this->media_object->getId();
                    }

                    $this->mob_mapping[$this->media_object->getImportId()]
                            = $this->media_object->getId();
                } else {
                    // get the id from mapping
                    $this->media_object->setId($this->mob_mapping[$this->media_object->getImportId()]);

                    // update "real" (no alias) media object
                    // (note: we overwrite any data from the media object
                    // created by an MediaAlias, only the data of the real
                    // object is stored in db separately; data of the
                    // MediaAliases are within the page XML
                    if (!$this->media_object->isAlias()) {
                        // now the media items are saved within the db
                        $this->media_object->update();

                        //echo "<br>update media object :".$this->media_object->getId().":";

                        // collect mobs with internal links
                        if ($this->media_object->containsIntLink()) {
                            //echo "got int link :".$this->media_object->getId().":<br>";
                            $this->mobs_with_int_links[] = $this->media_object->getId();
                        }
                    }
                }

                // append media alias to page, if we are in a page
                if ($this->in_page_object || $this->in_glossary_definition) {
                    if ($a_name != "InteractiveImage") {
                        $this->page_object->appendXMLContent($this->media_object->getXML(IL_MODE_ALIAS));
                        //echo "Appending:".htmlentities($this->media_object->getXML(IL_MODE_ALIAS))."<br>";
                    }
                }

                break;

            case "MediaItem":
            case "MediaAliasItem":
                $this->in_media_item = false;
                $this->media_object->addMediaItem($this->media_item);
                break;

            case "MapArea":
                $this->in_map_area = false;
                $this->media_item->addMapArea($this->map_area);
                break;

            case "Properties":
                $this->in_properties = false;
                if ($this->content_object->getType() == "lm") {
                    $this->content_object->update();
                }
                break;

            case "MetaData":
                $this->in_meta_data = false;
                if (strtolower(get_class($this->current_object)) == "illmpageobject" && !$this->in_media_object) {
                    // Metadaten eines PageObjects sichern in NestedSet
                    if (is_object($this->lm_page_object)) {
                        // update title/description of page object
                        $this->current_object->MDUpdateListener('General');
                        ilLMObject::_writeImportId(
                            $this->current_object->getId(),
                            $this->current_object->getImportId()
                        );
                    }
                } elseif ((strtolower(get_class($this->current_object)) == "ilobjquestionpool" ||
                    strtolower(get_class($this->current_object)) == "ilobjtest") &&
                    !$this->in_media_object) {
                    //					!$this->in_media_object && !$this->in_page_object)
                    // changed for imports of ILIAS 2 Tests where PageObjects could have
                    // Metadata sections (Helmut Schottmüller, 2005-12-02)
                    if ($this->metadata_parsing_disabled) {
                        $this->enableMDParsing(true);
                    } else {
                        if ($this->in_page_object && !is_null($this->page_object)) {
                            /*
                            $this->page_object->MDUpdateListener('General');
                            ilLMObject::_writeImportId(
                                $this->page_object->getId(),
                                $this->page_object->getImportId()
                            );*/
                        } else {
                            $this->current_object->MDUpdateListener('General');
                            ilLMObject::_writeImportId(
                                $this->current_object->getId(),
                                $this->current_object->getImportId()
                            );
                        }
                    }
                } elseif (strtolower(get_class($this->current_object)) == "ilstructureobject") {    // save structure object at the end of its meta block
                    // determine parent
                    $cnt = count($this->structure_objects);
                    if ($cnt > 1) {
                        $parent_id = $this->structure_objects[$cnt - 2]->getId();
                    } else {
                        $parent_id = $this->lm_tree->getRootId();
                    }

                    // create structure object and put it in tree
                    //$this->current_object->create(true); // now on top
                    $this->st_into_tree[] = array("id" => $this->current_object->getId(),
                        "parent" => $parent_id);

                    // update title/description of structure object
                    $this->current_object->MDUpdateListener('General');
                    ilLMObject::_writeImportId(
                        $this->current_object->getId(),
                        $this->current_object->getImportId()
                    );
                } elseif (strtolower(get_class($this->current_object)) == "ilobjlearningmodule" ||
                    strtolower(get_class($this->current_object)) == "ilobjcontentobject" ||
                    (strtolower(get_class($this->current_object)) == "ilobjglossary" && $this->in_glossary)) {
                    // todo: saving of md? getting title/descr and
                    // set it for current object
                } elseif (strtolower(get_class($this->current_object)) == "ilglossarydefinition" && !$this->in_media_object) {
                    // now on top
                    //$this->glossary_definition->create();

                    $this->page_object->setId($this->glossary_definition->getId());
                    $this->page_object->updateFromXML();

                    // todo: saving of md? getting title/descr and
                    // set it for current object
                }


                if (strtolower(get_class($this->current_object)) == "ilobjlearningmodule" ||
                    strtolower(get_class($this->current_object)) == "ilobjglossary") {
                    if (strtolower(get_class($this->current_object)) == "ilobjglossary" &&
                        $this->content_object->getType() != "glo") {
                        //echo "<br><b>getting2: ".$this->content_object->getTitle()."</b>";
                        $this->current_object->setTitle($this->content_object->getTitle() . " - " .
                            $this->lng->txt("glossary"));
                    }

                    $this->current_object->MDUpdateListener('General');
                    /*
                    if (!$this->in_media_object && $this->processMeta())
                    {
                        $this->current_object->update();
                    }
                    */
                }

                if ($this->in_media_object) {
                    //echo "<br>call media object update listener";
                    $this->media_object->MDUpdateListener('General');
                }

                if ($this->in_glossary_definition) {
                    $this->glossary_definition->MDUpdateListener('General');
                }

                break;

            case "Meta-Metadata":
                $this->in_meta_meta_data = false;
                break;

            case "FileItem":
                $this->in_file_item = false;
                // only update new file items
                if ($this->file_item->getImportId()) {
                    $this->file_item->update();
                }
                break;


            case "Table":
                unset($this->container[count($this->container) - 1]);
                break;

            case "Glossary":
                $this->in_glossary = false;
                break;

            case "GlossaryTerm":
                $term = trim($this->chr_data);
                $term = str_replace("&lt;", "<", $term);
                $term = str_replace("&gt;", ">", $term);
                $this->glossary_term->setTerm($term);
                $this->glossary_term->create();
                $iia = explode("_", $this->glossary_term->getImportId());
                $this->glossary_term_map[(int) $iia[count($iia) - 1]] = $this->glossary_term->getId();
                break;

            case "Paragraph":
                $this->inside_code = false;
                break;

            case "Definition":
                $this->in_glossary_definition = false;
                $this->page_object->updateFromXML();
                $this->page_object->buildDom();
                $this->glossary_definition->setShortText($this->page_object->getFirstParagraphText());
                $this->glossary_definition->update();
                if ($this->page_object->containsIntLink()) {
                    $this->pages_to_parse["gdf:" . $this->page_object->getId()] = "gdf:" . $this->page_object->getId();
                }
                if ($this->page_object->needsImportParsing()) {
                    $this->pages_to_parse["gdf:" . $this->page_object->getId()] = "gdf:" . $this->page_object->getId();
                }
                break;

            case "Format":
                if ($this->in_media_item) {
                    $this->media_item->setFormat(trim($this->chr_data));
                }
                break;

            case "Title":
                if (!$this->in_media_object) {
                    $this->current_object->setTitle(trim($this->chr_data));
                } else {
                    $this->media_object->setTitle(trim($this->chr_data));
                }
                break;

            case "Description":
            case "Language":
                break;

            case "Caption":
                if ($this->in_media_object) {
                    $this->media_item->setCaption(trim($this->chr_data));
                }
                break;

            case "TextRepresentation":
                if ($this->in_media_object) {
                    $this->media_item->setTextRepresentation(trim($this->chr_data));
                }
                break;

                // Location
            case "Location":
                // TODO: adapt for files in "real" subdirectories
                if ($this->in_media_item) {
                    $this->media_item->setLocationType($this->loc_type);
                    if ($this->loc_type == "Reference") {
                        $this->media_item->setLocation(str_replace("&", "&amp;", trim($this->chr_data)));
                    } else {
                        $this->media_item->setLocation(trim($this->chr_data));
                    }
                }
                if ($this->in_file_item) {
                    // set file name from xml file
                    $this->file_item->setFileName(trim($this->chr_data));

                    // special handling for file names with special characters
                    // (e.g. "&gt;")
                    if ($this->file_item->getType() == "file" &&
                        is_int(strpos($this->chr_data, "&")) &&
                        is_int(strpos($this->chr_data, ";"))) {
                        $imp_dir = $this->import_dir;
                        $source_dir = $imp_dir . "/" . $this->subdir . "/objects/" .
                            $this->file_item->getImportId();

                        // read "physical" file name from directory
                        if ($dir = opendir($source_dir)) {
                            while (false !== ($file = readdir($dir))) {
                                if ($file != "." && $file != "..") {
                                    $this->file_item->setFileName($file);
                                }
                            }
                            closedir($dir);
                        }
                    }

                    // set file item title
                    $this->file_item->setTitle(trim($this->chr_data));
                }
                break;

        }
        $this->endElement($a_name);
        $this->chr_data = "";
    }

    public function handlerCharacterData($a_xml_parser, $a_data) : void
    {
        // call meta data handler
        if ($this->in_meta_data && $this->processMeta()) {
            // cache beginning of meta data within media object tags
            // (we need to know the id, after that we send the cached data
            // to the meta xml handler)
            if ($this->in_media_object && $this->media_meta_start) {
                $this->media_meta_cache[] =
                    array("type" => "handlerCharacterData", "par1" => $a_data);
            } else {
                parent::handlerCharacterData($a_xml_parser, $a_data);
            }
        }

        // the parser converts "&gt;" to ">" and "&lt;" to "<"
        // in character data, but we don't want that, because it's the
        // way we mask user html in our content, so we convert back...

        $a_data = str_replace("<", "&lt;", $a_data);
        $a_data = str_replace(">", "&gt;", $a_data);


        // DELETE WHITESPACES AND NEWLINES OF CHARACTER DATA
        $a_data = preg_replace("/\n/", "", $a_data);
        if (!$this->inside_code) {
            $a_data = preg_replace("/\t+/", "", $a_data);
        }

        $this->chr_data .= $a_data;

        if (!empty($a_data) || $a_data === "0") {
            // append all data to page, if we are within PageObject,
            // but not within MetaData or MediaObject
            if (($this->in_page_object || $this->in_glossary_definition)
                && !$this->in_meta_data && !$this->in_media_object) {
                $this->page_object->appendXMLContent($a_data);
            }

            switch ($this->getCurrentElement()) {

                case "IntLink":
                case "ExtLink":
                    if ($this->in_map_area) {
                        $this->map_area->appendTitle($a_data);
                    }
                    break;

            }
        }
    }

    /**
     * @param resource $a_xml_parser
     */
    public function emptyMediaMetaCache($a_xml_parser) : void
    {
        foreach ($this->media_meta_cache as $cache_entry) {
            switch ($cache_entry["type"]) {
                case "handlerBeginTag":
                    parent::handlerBeginTag(
                        $a_xml_parser,
                        $cache_entry["par1"],
                        $cache_entry["par2"]
                    );
                    break;

                case "handlerEndTag":
                    parent::handlerEndTag(
                        $a_xml_parser,
                        $cache_entry["par1"]
                    );
                    break;

                case "handlerCharacterData":
                    parent::handlerCharacterData(
                        $a_xml_parser,
                        $cache_entry["par1"]
                    );
                    break;
            }
        }

        $this->media_meta_start = false;
        $this->media_meta_cache[] = array();
    }

    /**
                 * @return mixed[]
                 */
    public function getGlossaryTermMap() : array
    {
        return $this->glossary_term_map;
    }
}
