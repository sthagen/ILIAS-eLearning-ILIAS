<?php

/* Copyright (c) 1998-2021 ILIAS open source, GPLv3, see LICENSE */

use ILIAS\Refinery\Factory as Refinery;
use ILIAS\Data\Factory as DataFactory;
use ILIAS\HTTP\Services as HttpServices;
use ILIAS\HTTP\Wrapper\RequestWrapper;

/**
 * Class ilCtrl provides processing control methods. A global
 * instance is available through $DIC->ctrl() or $ilCtrl.
 *
 * @author Thibeau Fuhrer <thf@studer.raimann.ch>
 */
final class ilCtrl implements ilCtrlInterface
{
    /**
     * @var string public POST command name.
     */
    public const CMD_POST = 'post';

    /**
     * different modes used for UI plugins (or in dev-mode).
     */
    public const UI_MODE_PROCESS = 'execComm';
    public const UI_MODE_HTML    = 'getHtml';

    /**
     * @var string command mode for asynchronous requests.
     */
    private const CMD_MODE_ASYNC = 'asynch';

    /**
     * @var string separator used for CID-traces.
     */
    private const CID_TRACE_SEPARATOR = ':';

    /**
     * HTTP request type constants, might be extended further when
     * accepting REST API.
     */
    private const HTTP_METHOD_POST = 'POST';
    private const HTTP_METHOD_GET  = 'GET';

    /**
     * HTTP request parameter names, that are needed throughout
     * this service.
     */
    public  const PARAM_CSRF_TOKEN      = 'token';
    private const PARAM_REDIRECT        = 'redirectSource';
    private const PARAM_BASE_CLASS      = 'baseClass';
    private const PARAM_CMD_FALLBACK    = 'fallbackCmd';
    private const PARAM_CMD_CLASS       = 'cmdClass';
    private const PARAM_CMD_MODE        = 'cmdMode';
    private const PARAM_CMD_TRACE       = 'cmdNode';
    private const PARAM_CMD             = 'cmd';

    /**
     * @var ilPluginAdmin
     */
    private ilPluginAdmin $plugin_service;

    /**
     * @var HttpServices
     */
    private HttpServices $http_service;

    /**
     * @var ilDBInterface
     */
    private ilDBInterface $database;

    /**
     * @var RequestWrapper
     */
    private RequestWrapper $request;

    /**
     * @var Refinery
     */
    private Refinery $refinery;

    /**
     * Holds the cached CID's mapped to their according structure information.
     *
     * @var array<string, string>
     */
    private static array $cid_mapped_structure = [];

    /**
     * Holds the saved parameters of each class.
     *
     * @see ilCtrl::saveParameterByClass(), ilCtrl::saveParameter()
     *
     * @var array<string, array>
     */
    private array $saved_parameters = [];

    /**
     * Holds the set parameters of each class.
     *
     * @see ilCtrl::setParameterByClass(), ilCtrl::setParameter()
     *
     * @var array<string, array>
     */
    private array $parameters = [];

    /**
     * Holds the base-script for link targets.
     *
     * @var string
     */
    private string $target_script = 'ilias.php';

    /**
     * @var array<string, string>
     */
    private array $return_classes = [];

    /**
     * Holds the stacktrace of each call made with this ilCtrl instance.
     *
     * @var array<int, string>
     */
    private array $stacktrace = [];

    /**
     * Holds the fallback baseclass this service got initialized with.
     *
     * @var string
     */
    private string $fallback_baseclass;

    /**
     * Holds the current CID trace (e.g. 'cid1:cid2:cid3').
     *
     * @var string
     */
    private string $cid_trace;

    /**
     * Holds the read control structure from the php artifact.
     *
     * @var array<string, string>
     */
    private array $structure;

    /**
     * ilCtrl constructor
     */
    public function __construct()
    {
        /**
         * @var $DIC \ILIAS\DI\Container
         */
        global $DIC;

        $this->structure = require ilCtrlStructureArtifactObjective::ARTIFACT_PATH;

        $this->http_service = $DIC->http();
        $this->database     = $DIC->database();
        $this->request      = $this->getRequest();

        if (isset($DIC['ilPluginAdmin'])) {
            $this->plugin_service = $DIC['ilPluginAdmin'];
        }

        // $DIC->refinery() is not initialized at this point.
        $this->refinery = new Refinery(
            new DataFactory(),
            $DIC->language()
        );
    }

    // BEGIN PRIVATE METHODS
    // @TODO: move private methods to the bottom of $this

    /**
     * Populates a call by making a stacktrace entry for the given information.
     *
     * @param string      $class_name
     * @param string      $cmd
     * @param string|null $mode
     */
    private function populateCall(string $class_name, string $cmd, string $mode = null) : void
    {
        $this->stacktrace[] = [
            'class' => $class_name,
            'cmd'   => $cmd,
            'mode'  => $mode,
        ];
    }

    /**
     * Returns the request wrapper according to the HTTP method.
     *
     * @return RequestWrapper
     * @throws ilException if the HTTP method is not supported.
     */
    private function getRequest() : RequestWrapper
    {
        $request_method = $this->getRequestMethod();

        switch ($request_method) {
            case self::HTTP_METHOD_POST:
                return $this->http_service->wrapper()->post();

            case self::HTTP_METHOD_GET:
                return $this->http_service->wrapper()->query();

            default:
                throw new ilException("HTTP request method '$request_method' is not yet supported.");
        }
    }

    /**
     * Returns the classname of the current request's baseclass.
     *
     * Because this method is potentially called multiple times, the
     * determined baseclass is stored in a static variable.
     *
     * @return string
     * @throws ilException if the fallback baseclass was not yet initialized.
     */
    private function getBaseClass() : string
    {
        static $base_class_name;

        if (!isset($base_class_name)) {
            if ($this->request->has(self::PARAM_BASE_CLASS)) {
                $class_name = $this->request->retrieve(
                    self::PARAM_BASE_CLASS,
                    $this->refinery->to()->string()
                );

                $base_class_name = strtolower($class_name);
            } else {
                if (!isset($this->fallback_baseclass)) {
                    throw new ilException(self::class . "::initBaseClass() was not called yet.");
                }

                $base_class_name = $this->fallback_baseclass;
            }
        }

        return $base_class_name;
    }

    /**
     * Returns the information stored in the artifact for the
     * given classname.
     *
     * @param string $class_name
     * @return array<int, string>
     * @throws ilException if the classname was not read.
     */
    private function getClassInfoByName(string $class_name) : array
    {
        // lowercase the $class_name in case the developer forgot.
        $class_name = strtolower($class_name);

        if (!isset($this->structure[$class_name])) {
            throw new ilException("Class '$class_name' was not yet read by the " . ilCtrlStructureReader::class . ". Try `composer du` to build artifacts first.");
        }

        return $this->structure[$class_name];
    }

    /**
     * Returns the information stored in the artifact for the given CID.
     *
     * @param string $cid
     * @return array<int, string>
     * @throws ilException if the given CID was not found.
     */
    private function getClassInfoByCid(string $cid) : array
    {
        // check the cached cid-map for an existing entry.
        if (isset(self::$cid_mapped_structure[$cid])) {
            return self::$cid_mapped_structure[$cid];
        }

        foreach ($this->structure as $class_info) {
            foreach ($class_info as $key => $value) {
                if (ilCtrlStructureReader::KEY_CID === $key && $cid === $value) {
                    // store a cached cid-map entry for the found information.
                    self::$cid_mapped_structure[$cid] = $class_info;
                    return $class_info;
                }
            }
        }

        throw new ilException("The demanded CID '$cid' was not found. Try `composer du` to create artifacts first.");
    }

    /**
     * Returns the CID trace for the provided classname.
     *
     * @param string $target_class
     * @param string $cid_trace
     * @return string|null
     */
    private function getTraceForTargetClass(string $target_class, string $cid_trace) : ?string
    {
        // lowercase the $target_class in case the developer forgot.
        $target_class = strtolower($target_class);
        $target_info  = $this->getClassInfoByName($target_class);
        $target_cid   = $this->getCidFromInfo($target_info);

        // the target cid can be returned, if its the only one in trace.
        if ($target_cid === $cid_trace) {
            return $target_cid;
        }

        $current_cid  = $this->getCurrentCidFromTrace($cid_trace);
        $current_info = $this->getClassInfoByCid($current_cid);

        // the target cid can be returned, if it's the current cid from trace.
        if ($target_cid === $current_cid) {
            return $target_cid;
        }

        // the target cid is appended, if it's a child of the current cid.
        // (a child is, when a target class is called by (@ilCtrl_calledBy)
        // another class.)
        if (in_array($target_class, $this->getCalledClassesFromInfo($current_info), true)) {
            return
                $cid_trace . self::CID_TRACE_SEPARATOR . $target_cid
            ;
        }

        $parent_cid  = $this->getParentCidFromTrace($cid_trace);
        if (null !== $parent_cid) {
            $parent_info = $this->getClassInfoByCid($parent_cid);

            // the target cid is appended, if it's a sibling of the current cid.
            // (a sibling is, when a target class shares the same parent class,
            // from whom it is called from (@ilCtrl_calls).)
            if (in_array($target_class, $this->getCalledClassesFromInfo($parent_info), true)) {
                // @TODO: figure out, why we need to remove the current CID here.
                $cid_trace = $this->removeCurrentCidFromTrace($cid_trace) ?? $cid_trace;
                return
                    $cid_trace . self::CID_TRACE_SEPARATOR . $target_cid
                ;
            }
        }

        // @TODO: finish the whatever's happening in legacy here, here.

        return null;
    }

    /**
     * Returns the classname of the baseclass for the given cid trace.
     *
     * @param string $cid_trace
     * @return string|null
     * @throws ilException
     */
    private function getBaseClassByTrace(string $cid_trace) : ?string
    {
        // get the most left position of a trace separator.
        $position = strpos($cid_trace, self::CID_TRACE_SEPARATOR);

        // if a position was found, the trace can be reduced to that position.
        if ($position) {
            $base_class_cid  = substr($cid_trace, 0, $position);
            $base_class_info = $this->getClassInfoByCid($base_class_cid);

            return $this->getClassFromInfo($base_class_info);
        }

        return null;
    }

    /**
     * Returns the last appended CID from a cid-trace.
     *
     * @param string $cid_trace
     * @return string
     */
    private function getCurrentCidFromTrace(string $cid_trace) : ?string
    {
        if ('' === $cid_trace) {
            return null;
        }

        $trace = explode(self::CID_TRACE_SEPARATOR, $cid_trace);
        $key   = (count($trace) - 1);

        return $trace[$key];
    }

    /**
     * Returns the second-last appended CID from a cid-trace, if it exists.
     *
     * @param string $cid_trace
     * @return string|null
     */
    private function getParentCidFromTrace(string $cid_trace) : ?string
    {
        if ('' === $cid_trace) {
            return null;
        }

        $trace = explode(self::CID_TRACE_SEPARATOR, $cid_trace);
        $key   = (count($trace) - 2);

        // abort if the index and therefore no parent exists.
        if (0 > $key) {
            return null;
        }

        return $trace[$key];
    }

    /**
     * Returns the given CID trace without the current CID or null, if
     * it was the only CID.
     *
     * @param string $cid_trace
     * @return string|null
     */
    private function removeCurrentCidFromTrace(string $cid_trace) : ?string
    {
        // get the most right position of a trace separator.
        $position = strrpos($cid_trace, self::CID_TRACE_SEPARATOR);

        // if a position was found, the trace can be reduced to that position.
        if ($position) {
            return substr($cid_trace, 0, $position);
        }

        return null;
    }

    /**
     * Returns the cid trace of each cid within the given trace.
     *
     *      $example = array(
     *          'cid1',
     *          'cid1:cid2',
     *          'cid1:cid2:cid3',
     *          ...
     *      );
     *
     * @param string $cid_trace
     * @return array<int, string>
     */
    private function getPathsForTrace(string $cid_trace) : array
    {
        if ('' === $cid_trace) {
            return [];
        }

        $cids = explode(self::CID_TRACE_SEPARATOR, $cid_trace);
        $paths = [];

        foreach ($cids as $i => $cid) {
            if ($i === 0) {
                // on first iteration the cid is added.
                $paths[] = $cid;
            } else {
                // on every other iteration the cid is appended to the
                // one from the last iteration.
                $paths[] = $paths[($i - 1)] . self::CID_TRACE_SEPARATOR . $cid;
            }
        }

        return $paths;
    }

    /**
     * Removes old or unnecessary tokens from the database if the answer to
     * life, the universe and everything is generated.
     *
     * @param ilRandom $random
     */
    private function maybeDeleteOldTokens(ilRandom $random) : void
    {
        if (42 === $random->int(1, 200)) {
            $datetime = new ilDateTime(time(), IL_CAL_UNIX);
            $datetime->increment(IL_CAL_DAY, -1);
            $datetime->increment(IL_CAL_HOUR, -12);

            $this->database->manipulateF(
                "DELETE FROM il_request_token WHERE stamp < %s;",
                ['timestamp'],
                [$datetime->get(IL_CAL_TIMESTAMP)]
            );
        }
    }

    /**
     * Helper function to fetch CID of passed class information.
     *
     * @param array $class_info
     * @return string
     */
    private function getCidFromInfo(array $class_info) : string
    {
        return $class_info[ilCtrlStructureReader::KEY_CID];
    }

    /**
     * Helper function to fetch classname of passed class information.
     *
     * @param array $class_info
     * @return string
     */
    private function getClassFromInfo(array $class_info) : string
    {
        return $class_info[ilCtrlStructureReader::KEY_CLASS_NAME];
    }

    /**
     * Helper function to fetch called classes of passed class information.
     *
     * @param array $class_info
     * @return array
     */
    private function getCalledClassesFromInfo(array $class_info) : array
    {
        return $class_info[ilCtrlStructureReader::KEY_CALLS];
    }

    /**
     * Helper function to fetch called-by classes of passed class information.
     *
     * @param array $class_info
     * @return array
     */
    private function getCalledByClassesFromInfo(array $class_info) : array
    {
        return $class_info[ilCtrlStructureReader::KEY_CALLED_BY];
    }

    /**
     * Helper function to return the current HTTP request method.
     *
     * @return string
     */
    private function getRequestMethod() : string
    {
        return $_SERVER['REQUEST_METHOD'];
    }

    // END PRIVATE METHODS

    /**
     * @inheritDoc
     */
    public function callBaseClass() : void
    {
        $class_name = $this->getBaseClass();
        $class_info = $this->getClassInfoByName($class_name);
        $class_name = $this->getClassFromInfo($class_info);

        $this->cid_trace = $this->getCidFromInfo($class_info);

        $this->forwardCommand(new $class_name());
    }

    /**
     * @inheritDoc
     */
    public function getModuleDir()
    {
        throw new ilException(self::class . "::getModuleDir is deprecated.");
    }

    /**
     * @inheritDoc
     */
    public function forwardCommand(object $a_gui_object)
    {
        $class_name = strtolower(get_class($a_gui_object));
        $cid_trace  = $this->getTraceForTargetClass($class_name, $this->cid_trace);

        if (null === $cid_trace) {
            throw new ilException("Cannot forward to class '$class_name', CID-Trace could not be generated.");
        }

        // update current cid trace and populate the call
        $this->cid_trace = $cid_trace;
        $this->populateCall(
            $class_name,
            //$this->getCmd(),
            'onlytest',
            self::UI_MODE_PROCESS
        );

        return $a_gui_object->executeCommand();
    }

    /**
     * @inheritDoc
     */
    public function getHTML($a_gui_object, array $a_parameters = null, array $class_path = []) : string
    {
        $class_name = strtolower(get_class($a_gui_object));
        $base_class = '';

        if (0 < count($class_path)) {

        }

        return '';
    }

    /**
     * @inheritDoc
     */
    public function setContext($a_obj_id, $a_obj_type, $a_sub_obj_id = 0, $a_sub_obj_type = "")
    {
        // TODO: Implement setContext() method.
    }

    /**
     * @inheritDoc
     */
    public function getContextObjId()
    {
        // TODO: Implement getContextObjId() method.
    }

    /**
     * @inheritDoc
     */
    public function getContextObjType()
    {
        // TODO: Implement getContextObjType() method.
    }

    /**
     * @inheritDoc
     */
    public function getContextSubObjId()
    {
        // TODO: Implement getContextSubObjId() method.
    }

    /**
     * @inheritDoc
     */
    public function getContextSubObjType()
    {
        // TODO: Implement getContextSubObjType() method.
    }

    /**
     * @inheritDoc
     */
    public function checkTargetClass($a_class)
    {
        // TODO: Implement checkTargetClass() method.
    }

    /**
     * @inheritDoc
     */
    public function getCmdNode() : string
    {
        // TODO: Implement getCmdNode() method.
    }

    /**
     * @inheritDoc
     */
    public function addTab($a_lang_var, $a_link, $a_cmd, $a_class)
    {
        // TODO: Implement addTab() method.
    }

    /**
     * @inheritDoc
     */
    public function getTabs()
    {
        // TODO: Implement getTabs() method.
    }

    /**
     * @inheritDoc
     */
    public function getCallHistory() : array
    {
        return $this->stacktrace;
    }

    /**
     * @inheritDoc
     */
    public function getCallStructure($a_class)
    {
        // TODO: Implement getCallStructure() method.
    }

    /**
     * @inheritDoc
     */
    public function readCallStructure($a_class, $a_nr = 0, $a_parent = 0)
    {
        // TODO: Implement readCallStructure() method.
    }

    /**
     * @inheritDoc
     */
    public function saveParameter($a_obj, $a_parameter)
    {
        // TODO: Implement saveParameter() method.
    }

    /**
     * @inheritDoc
     */
    public function saveParameterByClass($a_class, $a_parameter)
    {
        // TODO: Implement saveParameterByClass() method.
    }

    /**
     * @inheritDoc
     */
    public function setParameter($a_obj, $a_parameter, $a_value)
    {
        // TODO: Implement setParameter() method.
    }

    /**
     * @inheritDoc
     */
    public function setParameterByClass($a_class, $a_parameter, $a_value)
    {
        // TODO: Implement setParameterByClass() method.
    }

    /**
     * @inheritDoc
     */
    public function clearParameterByClass($a_class, $a_parameter)
    {
        // TODO: Implement clearParameterByClass() method.
    }

    /**
     * @inheritDoc
     */
    public function clearParameters($a_obj)
    {
        // TODO: Implement clearParameters() method.
    }

    /**
     * @inheritDoc
     */
    public function clearParametersByClass($a_class)
    {
        // TODO: Implement clearParametersByClass() method.
    }

    /**
     * @inheritDoc
     */
    public function getNextClass($a_gui_class = null)
    {
        // TODO: Implement getNextClass() method.
    }

    /**
     * @inheritDoc
     */
    public function lookupClassPath($a_class_name)
    {
        // TODO: Implement lookupClassPath() method.
    }

    /**
     * @inheritDoc
     */
    public function getClassForClasspath($a_class_path)
    {
        // TODO: Implement getClassForClasspath() method.
    }

    /**
     * @inheritDoc
     */
    public function setTargetScript(string $a_target_script) : void
    {
        $this->target_script = $a_target_script;
    }

    /**
     * @inheritDoc
     */
    public function getTargetScript() : string
    {
        return $this->target_script;
    }

    /**
     * @inheritDoc
     */
    public function initBaseClass(string $a_base_class) : void
    {
        $this->fallback_baseclass = strtolower($a_base_class);
    }

    /**
     * @inheritDoc
     */
    public function getCmd(string $fallback_command = '', array $safe_commands = []) : string
    {
        $command = null;
        if ($this->request->has(self::PARAM_CMD)) {
            $command = $this->request->retrieve(
                self::PARAM_CMD,
                $this->refinery->custom()->transformation(
                    static function ($cmd) {
                        // command can always be returned, as it's used exactly how
                        // it is processed. (either array or plain string)
                        return $cmd;
                    }
                )
            );
        }

        $x = 1;

        if (self::CMD_POST === $command) {

        }

        if (is_array($command)) {
            $command = array_key_first($command);
        }

        return $command ?? $fallback_command;
    }

    /**
     * @inheritDoc
     */
    public function setCmd($a_cmd)
    {
        // TODO: Implement setCmd() method.
    }

    /**
     * @inheritDoc
     */
    public function setCmdClass($a_cmd_class) : void
    {
        // @TODO: implement setCmdClass() method.
    }

    /**
     * @inheritDoc
     */
    public function getCmdClass() : string
    {
        if ($this->request->has(self::PARAM_CMD_CLASS)) {
            return $this->request->retrieve(
                self::PARAM_CMD_CLASS,
                $this->refinery->to()->string()
            );
        }

        return '';
    }

    /**
     * @inheritDoc
     */
    public function getFormAction(
        object $a_gui_object,
        string $a_fallback_cmd = "",
        string $a_anchor = "",
        bool $a_asynch = false,
        bool $xml_style = false
    ) : string {
        return $this->getFormActionByClass(
            get_class($a_gui_object),
            $a_fallback_cmd,
            $a_anchor,
            $a_asynch,
            $xml_style
        );
    }

    /**
     * @inheritDoc
     */
    public function getFormActionByClass(
        $a_class,
        string $a_fallback_cmd = "",
        string $a_anchor = "",
        bool $a_asynch = false,
        bool $xml_style = false
    ) : string {
        $form_action = $this->getLinkTargetByClass(
            $a_class,
            self::CMD_POST,
            '',
            $a_asynch,
            $xml_style
        );

        $this->appendUrlParameterString(
            $form_action,
            self::PARAM_CSRF_TOKEN,
            $this->getRequestToken(),
            $xml_style
        );

        if ('' !== $a_fallback_cmd) {
            $this->appendUrlParameterString(
                $form_action,
                self::PARAM_CMD_FALLBACK,
                $a_fallback_cmd,
                $xml_style
            );
        }

        if ('' !== $a_anchor) {
            $form_action .= '#' . $a_anchor;
        }

        return $form_action;
    }

    /**
     * @inheritDoc
     */
    public function appendRequestTokenParameterString(string $a_url, bool $xml_style = false) : string
    {
        $this->appendUrlParameterString(
            $a_url,
            self::PARAM_CSRF_TOKEN,
            $this->getRequestToken(),
            $xml_style
        );

        return $a_url;
    }

    /**
     * @inheritDoc
     */
    public function getRequestToken() : string
    {
        global $DIC;
        static $token;

        if (isset($token)) {
            return $token;
        }

        $user_id = $DIC->user()->getId();
        if (0 <= $user_id && ANONYMOUS_USER_ID !== $user_id) {
            $token_result = $this->database->fetchAssoc(
                $this->database->queryF(
                    "SELECT token FROM il_request_token WHERE user_id = %s AND session_id = %s;",
                    ['integer', 'text'],
                    [$user_id, session_id()]
                )
            );

            if (isset($token_result['token'])) {
                $token = $token_result['token'];
            }

            $random = new ilRandom();
            $token  = md5(uniqid($random->int(), true));

            $this->database->manipulateF(
                "INSERT INTO il_request_token (user_id, token, stamp, session_id) VALUES (%s, %s, %s, %s);",
                [
                    'integer',
                    'text',
                    'timestamp',
                    'text',
                ],
                [
                    $user_id,
                    $token,
                    $this->database->now(),
                    session_id(),
                ]
            );

            $this->maybeDeleteOldTokens($random);
        } else {
            $token = '';
        }

        return $token;
    }

    /**
     * @inheritDoc
     */
    public function redirect(
        object $a_gui_obj,
        string $a_cmd = "",
        string $a_anchor = "",
        bool $a_asynch = false
    ) : void {
        $this->redirectToURL(
            $this->getLinkTargetByClass(
                get_class($a_gui_obj),
                $a_cmd,
                $a_anchor,
                $a_asynch
            )
        );
    }

    /**
     * @inheritDoc
     */
    public function redirectToURL(string $a_url) : void
    {
        if (!is_int(strpos($a_url, '://'))) {
            if (defined('ILIAS_HTTP_PATH') && 0 !== strpos($a_url, '/')) {
                if (is_int(strpos($_SERVER['PHP_SELF'], '/setup/'))) {
                    $a_url = 'setup/' . $a_url;
                }

                $a_url = ILIAS_HTTP_PATH . '/' . $a_url;
            }
        }

        if (null !== $this->plugin_service) {
            $plugin_names = ilPluginAdmin::getActivePluginsForSlot(
                IL_COMP_SERVICE,
                'UIComponent',
                'uihk'
            );

            if (!empty($plugin_names)) {
                foreach ($plugin_names as $plugin) {
                    $plugin = ilPluginAdmin::getPluginObject(
                        IL_COMP_SERVICE,
                        'UIComponent',
                        'uihk',
                        $plugin
                    );

                    /**
                     * @var $plugin ilUserInterfaceHookPlugin
                     *
                     * @TODO: THIS IS LEGACY CODE! Methods are deprecated an should not
                     *        be used anymore. There is no other implementation yet,
                     *        therefore it stays for now.
                     */
                    $gui_object = $plugin->getUIClassInstance();
                    $resp = $gui_object->getHTML("Services/Utilities", "redirect", array( "html" => $a_url ));
                    if ($resp["mode"] != ilUIHookPluginGUI::KEEP) {
                        $a_url = $gui_object->modifyHTML($a_url, $resp);
                    }
                }
            }
        }

        // Manually trigger to write and close the session. This has the advantage that if an exception is thrown
        // during the writing of the session (ILIAS writes the session into the database by default) we get an exception
        // if the session_write_close() is triggered by exit() then the exception will be dismissed but the session
        // is never written, which is a nightmare to develop with.
        session_write_close();

        if ($this->http_service->request()->getHeaderLine('Accept')) {

        }
    }

    /**
     * @inheritDoc
     */
    public function redirectByClass(
        $a_class,
        string $a_cmd = "",
        string $a_anchor = "",
        bool $a_asynch = false
    ) : void {
        $this->redirectToURL(
            $this->getLinkTargetByClass(
                $a_class,
                $a_cmd,
                $a_anchor,
                $a_asynch
            )
        );
    }

    /**
     * @inheritDoc
     */
    public function isAsynch()
    {
        if ($this->request->has(self::PARAM_CMD_MODE)) {
            $mode = $this->request->retrieve(
                self::PARAM_CMD_MODE,
                $this->refinery->to()->string()
            );

            return (self::CMD_MODE_ASYNC === $mode);
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function getLinkTarget(
        object $a_gui_obj,
        string $a_cmd = "",
        string $a_anchor = "",
        bool $a_asynch = false,
        bool $xml_style = false
    ) : string {
        return $this->getLinkTargetByClass(
            get_class($a_gui_obj),
            $a_cmd,
            $a_anchor,
            $a_asynch,
            $xml_style
        );
    }

    /**
     * @inheritDoc
     */
    public function getLinkTargetByClass(
        $a_class,
        string $a_cmd = "",
        string $a_anchor = "",
        bool $a_asynch = false,
        bool $xml_style = false
    ) : string {
        // force xml style to be disabled for async requests
        if ($a_asynch) {
            $xml_style = false;
        }

        $url = $this->getTargetScript();
        $url = $this->getUrlParameters($a_class, $url, $a_cmd, $xml_style);

        if ($a_asynch) {
            $this->appendUrlParameterString(
                $url,
                self::PARAM_CMD_MODE,
                self::CMD_MODE_ASYNC
            );
        }

        if ('' !== $a_anchor) {
            $url .= "#" . $a_anchor;
        }

        return $url;
    }

    /**
     * @inheritDoc
     */
    public function setReturn(object $a_gui_obj, string $a_cmd) : void
    {
        $this->setReturnByClass(get_class($a_gui_obj), $a_cmd);
    }

    /**
     * @inheritDoc
     */
    public function setReturnByClass(string $a_class, string $a_cmd) : void
    {
        $class_name = strtolower($a_class);

        $script = $this->getTargetScript();
        $script = $this->getUrlParameters($class_name, $script, $a_cmd);

        $this->return_classes[$class_name] = $script;
    }

    /**
     * @inheritDoc
     */
    public function returnToParent(object $a_gui_obj, string $a_anchor = null) : void
    {
        $class_name = strtolower(get_class($a_gui_obj));
        $target_url = $this->getReturnClass($class_name);

        if (!$target_url) {
            throw new ilException("Cannot return from " . get_class($a_gui_obj) . ". The parent class was not found.");
        }

        $this->appendUrlParameterString(
            $target_url,
            self::PARAM_REDIRECT,
            $class_name
        );

        if ($this->request->has(self::PARAM_CMD_MODE)) {
            $cmd_mode = $this->request->retrieve(
                self::PARAM_CMD_MODE,
                $this->refinery->to()->string()
            );

            $this->appendUrlParameterString(
                $target_url,
                self::PARAM_CMD_MODE,
                $cmd_mode
            );
        }

        $this->redirectToURL($target_url);
    }

    /**
     * @inheritDoc
     */
    public function getParentReturn($a_gui_obj)
    {
        return $this->getReturnClass($a_gui_obj);
    }

    /**
     * @inheritDoc
     */
    public function getParentReturnByClass($a_class)
    {
        return $this->getReturnClass($a_class);
    }

    /**
     * @inheritDoc
     */
    public function getReturnClass($a_class)
    {
        if (is_object($a_class)) {
            $class_name = strtolower(get_class($a_class));
        } else {
            $class_name = strtolower($a_class);
        }

        $trace = $this->getTraceForTargetClass($class_name, $this->cid_trace);
        $cids  = explode(self::CID_TRACE_SEPARATOR, $trace);

        for ($i = count($cids); 0 <= $i; $i--) {
            $class_info = $this->getClassInfoByCid($cids[$i]);
            $class_name_of_iteration = $this->getClassFromInfo($class_info);
            if (isset($this->return_classes[$class_name_of_iteration])) {
                return $this->return_classes[$class_name_of_iteration];
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function getRedirectSource() : string
    {
        if ($this->request->has(self::PARAM_REDIRECT)) {
            return $this->request->retrieve(
                self::PARAM_REDIRECT,
                $this->refinery->to()->string()
            );
        }

        return '';
    }

    /**
     * Appends a parameter name and value to an existing URL string.
     *
     * This method was imported from @see ilUtil::appendUrlParameterString().
     *
     * @param string $url
     * @param string $parameter_name
     * @param mixed  $parameter_value
     * @param bool   $xml_style
     */
    private function appendUrlParameterString(string &$url, string $parameter_name, $parameter_value, bool $xml_style = false) : void
    {
        $amp = ($xml_style) ? "&amp;" : "&";

        $url = (is_int(strpos($url, "?"))) ?
            $url . $amp . $parameter_name . "=" . $parameter_value :
            $url . "?" . $parameter_name . "=" . $parameter_value
        ;
    }

    /**
     * @inheritDoc
     */
    public function getUrlParameters($a_classes, string $a_str, string $a_cmd = null, bool $xml_style = false) : string
    {
        $parameters = $this->getParameterArrayByClass($a_classes, $a_cmd);

        foreach ($parameters as $param_name => $value) {
            if ('' !== (string) $value) {
                $this->appendUrlParameterString(
                    $a_str,
                    $param_name,
                    $value,
                    $xml_style
                );
            }
        }

        return $a_str;
    }

    /**
     * @inheritDoc
     */
    public function getParameterArray($a_gui_obj, $a_cmd = null) : array
    {
        return $this->getParameterArrayByClass(get_class($a_gui_obj), $a_cmd);
    }

    /**
     * @inheritDoc
     */
    public function getParameterArrayByClass($classes, $a_cmd = null) : array
    {
        if (empty($classes)) {
            return [];
        }

        $current_base_class = null;
        foreach (((array) $classes) as $class) {
            $cid_trace  = $this->getTraceForTargetClass($class, $this->cid_trace);
            if (null !== $cid_trace) {
                $base_class = $this->getBaseClassByTrace($cid_trace);
                if ($base_class !== $this->getBaseClass()) {
                    $current_base_class = $base_class;
                }
            }
        }

        $cids = explode(self::CID_TRACE_SEPARATOR, $this->cid_trace);
        $parameters = [];

        foreach ($cids as $cid) {
            $class_info = $this->getClassInfoByCid($cid);
            $class_name = $this->getClassFromInfo($class_info);

            if (isset($this->saved_parameters[$class_name])) {
                foreach ($this->saved_parameters[$class_name] as $param_name) {
                    if ($this->request->has($param_name)) {
                        $parameters[$param_name] = $this->request->retrieve(
                            $param_name,
                            $this->refinery->to()->string()
                        );
                    } else {
                        $parameters[$param_name] = null;
                    }
                }
            }

            if (isset($this->parameters[$class_name])) {
                foreach ($this->parameters[$class_name] as $param_name => $value) {
                    $parameters[$param_name] = $value;
                }
            }
        }

        if (null !== $current_base_class) {
            $parameters[self::PARAM_BASE_CLASS] = $current_base_class;
        } else {
            $parameters[self::PARAM_BASE_CLASS] = $this->getBaseClass();
        }

        if ($a_cmd !== null) {
            $parameters[self::PARAM_CMD] = $a_cmd;
        }

        $target_cid  = $this->getCurrentCidFromTrace($this->cid_trace);
        $target_info = $this->getClassInfoByCid($target_cid);

        $parameters[self::PARAM_CMD_CLASS] = $this->getClassFromInfo($target_info);
        $parameters[self::PARAM_CMD_TRACE] = $this->cid_trace;

        return $parameters;
    }

    /**
     * @inheritDoc
     */
    public function insertCtrlCalls($a_parent, $a_child, $a_comp_prefix)
    {
        // TODO: Implement insertCtrlCalls() method.
    }

    /**
     * @inheritDoc
     */
    public function checkCurrentPathForClass($gui_class)
    {
        // TODO: Implement checkCurrentPathForClass() method.
    }

    /**
     * @inheritDoc
     */
    public function getCurrentClassPath() : array
    {
        // TODO: Implement getCurrentClassPath() method.
    }
}