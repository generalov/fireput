<?php

define('FIREPUT_BASEDIR', dirname(dirname(__FILE__)));
define('FIREPUT_CSS_LOWERCASEHEX', true);


class FireputService {

    static $DEFAULT_OPTS = array(
        'baseDir' => FIREPUT_BASEDIR,
    );

    protected $opts;

    public function __construct($opts=array()) {
        $this->opts = $opts ? array_merge(self::$DEFAULT_OPTS, $opts) : self::$DEFAULT_OPTS;
    }

    public function handleHttpRequest() {
        // Extract JSON data to $_POST
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            list($mimetype, $_) = explode(';', strtolower($_SERVER['CONTENT_TYPE']), 2);
            if ($mimetype === 'application/json') {
                $postdata = file_get_contents("php://input");
                $GLOBALS['_POST'] = json_decode($postdata, true);
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $q = new QueryDict($_POST);
            switch ($q->get('subType')) {
                case 'freeEdit':
                    $this->getCssEditor($q->get('href'))
                        ->freeEdit($q->get('cssText'));
                    break;
                case 'setProperty':
                    $this->getCssEditor($q->get('href'))
                        ->setProperty($q->get('propName'), $q->get('propValue'),
                                      $q->get('propPriority'), $q->get('ruleSelector'));
                    break;
                case 'removeProperty':
                    $this->getCssEditor($q->get('href'))
                        ->removeProperty($q->get('propName'), $q->get('ruleSelector'));
                    break;
                case 'deleteRule':
                    $this->getCssEditor($q->get('href'))
                        ->deleteRule($q->get('ruleIndex'));
                    break;
                case 'insertRule':
                    $this->getCssEditor($q->get('href'))
                       ->insertRule($q->get('ruleIndex'), $q->get('cssText'));
                    break;
                default : error_log("Unknown message " . var_export($q, true)); break;
            }
            $location = self::httpGetRequestedUri();
            return self::httpResponseSeeOther($location);
        } else { //if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            return self::httpResponseOk('Ok');
        }
    }

    protected function getCssEditor($uri) {
        $cssFileToEdit = new FileResource($uri, $this->opts['baseDir']);
        return new CssEditor($cssFileToEdit);
    }


    static function httpGetRequestedUri() {
        $schema = (!isset($_SERVER['HTTPS']) || strtolower($_SERVER['HTTPS']) != 'on')
                ? "http"
                : "https";
        $host = $_SERVER['HTTP_HOST'];
        $port = ($_SERVER['SERVER_PORT'] != '80' ? ":{$_SERVER['SERVER_PORT']}" : '');
        $requestUri = $_SERVER['REQUEST_URI'];
        return "$schema://$host$port$requestUri";
    }

    static function httpResponseOk($message) {
        $status = "200 OK";
        return self::httpResponse($status, $message);
    }

    static function httpResponseSeeOther($location) {
        $status = "302 Found";
        $message = $location;
        header("Location: $location");
        return self::httpResponse($status, $message);
    }

    static function httpResponse($status, $message=null) {
        if (substr(php_sapi_name(), 0, 3) == 'cgi') {
            header("Status: $status", TRUE);
        } else {
            header($_SERVER['SERVER_PROTOCOL'] . ' ' . $status);
            header("Status: $status", TRUE);
        }
        if (isset($message)) echo $message;
    }

}


class QueryDict {

    private $arr;

    public function __construct($arr) {
        $this->arr = $arr;
    }

    public function get($key/*, $default */) {
        if (!$this->offsetExists($key))
            if (func_num_args() == 2) // default
                return func_get_arg(1);
            else
                throw new Exception("KeyError '$key'");
        return $this->arr[$key];
    }

    public function offsetExists($key) {
        return array_key_exists($key, $this->arr);
    }

}


class CssEditor {

    static $DEFAULT_OPTS = array(
        'propIndent' => "\t",
        'propTemplate' => '%s: %s%s',
        'propLineTemplate' => "\n%s;",
        'useLowercaseHex'=> FIREPUT_CSS_LOWERCASEHEX,
    );

    protected $opts;
    private $cssFileToEdit;

    public function __construct($cssFileToEdit, $opts=array()) {
        $this->setFile($cssFileToEdit);
        $this->opts = $opts ? array_merge(self::$DEFAULT_OPTS, $opts) : self::$DEFAULT_OPTS;
    }

    public function getFile() {
        return $this->cssFileToEdit;
    }

    public function freeEdit($cssText) {
        $this->getFile()
            ->write($cssText);
    }

    public function removeProperty($propName, $ruleSelector) {
        error_log(__METHOD__."($propName, $ruleSelector)");
        $fileCss = $this->getFile();
        $prevCssText = $fileCss->read();
        $cssText = $this->cssSetProperty($prevCssText, $propName, '', '', $ruleSelector);
        $fileCss->write($cssText);
    }

    public function setProperty($propName, $propValue, $propPriority, $ruleSelector) {
        error_log(__METHOD__."($propName, $propValue, $propPriority, $ruleSelector)");

        $fileCss = $this->getFile();
        $prevCssText = $fileCss->read();
        $cssText = $this->cssSetProperty($prevCssText, $propName, $propValue, $propPriority, $ruleSelector);
        $fileCss->write($cssText);
    }

    public function insertRule($ruleIndex, $ruleCssText) {
        error_log(__METHOD__."($ruleIndex, $ruleCssText)");

        $fileCss = $this->getFile();
        $prevCssText = $fileCss->read();
        $cssRules = $this->cssSplitRules($prevCssText);
        array_splice($cssRules, $ruleIndex, 0, array($ruleCssText));
        $cssText = implode('', $cssRules);
        $fileCss->write($cssText);
    }

    public function deleteRule($ruleIndex) {
        error_log(__METHOD__."($ruleIndex)");

        $fileCss = $this->getFile();
        $prevCssText = $fileCss->read();
        $cssRules = $this->cssSplitRules($prevCssText);
        unset($cssRules[$ruleIndex]);
        $cssText = implode('', $cssRules);
        $fileCss->write($cssText);
    }

    protected function cssSetProperty($prevCssText, $propName, $propValue, $propPriority, $ruleSelector) {
        if ($this->opts['useLowercaseHex'])
            $propValue = preg_replace('/#[[:xdigit:]]+/ei', 'strtolower("$0")', $propValue);
        $r = '#';
        $ruleSelectorR = preg_replace('#[[:space:]]+#', '[[:space:]]+', preg_quote($ruleSelector, $r));
        if (preg_match("{$r}{$ruleSelectorR}[[:space:]]*{([^}]*?)}{$r}s", $prevCssText, $matches,  PREG_OFFSET_CAPTURE)) {
            list($_, list($prevRuleCssText, $ruleStart)) = $matches;
            $propNameR = preg_quote($propName, $r);
            $propText = sprintf($this->opts['propTemplate'], $propValue,
                    $propPriority ? " $propPriority" : '');

            if (preg_match("${r}(^|;)([[:space:]]*)({$propNameR}[[:space:]]*:[^;]+?)(;|$){$r}s", $prevRuleCssText, $matches)) {
                list($all, $prefix, $indent, $prevPropText, $suffix) = $matches;
                if ($propValue) {
                    # set existing property
                    if (!$prefix) $indent = $this->opts['propIndent'];
                    $ruleCssText = str_replace($all, "{$prefix}{$indent}{$propText}{$suffix}", $prevRuleCssText);
                } else {
                    # delete existing property
                    $ruleCssText = str_replace($all, "{$prefix}", $prevRuleCssText);
                }
            } else {
                if ($propValue) {
                    # add new property
                    $ruleCssText = rtrim($prevRuleCssText) . sprintf($this->opts['propLineTemplate'], $this->opts['propIndent'] . $propText);
                } else {
                    # delete new property
                    # it's not exists! do nothing.
                    $ruleCssText = $prevRuleCssText;
                }
            }

            $cssText = substr_replace($prevCssText, $ruleCssText, $ruleStart, strlen($prevRuleCssText));
        } else {
            throw new Exception(sprintf("Rule '$ruleSelector' not found at the '%s'", $this->getFile()));
        }
        return $cssText;
    }

    protected function cssSplitRules($cssText) {
        throw new Exception("Not implemented");
    }

}


class FileResource {

    private $uri;
    private $basedir;

    public function __construct($uri, $basedir) {
        $this->uri = $uri;
        $this->basedir = $basedir;
        if (!$this->isFile()) throw new Exception("File not found ".$this->getAbsolutePath());
    }

    public function read() {
        $filename = $this->getAbsolutePath();
        return file_get_contents($filename);
    }

    public function write($data) {
        $filename = $this->getAbsolutePath();
        if (file_put_contents($filename, $data) === false) {
            throw new Exception("Unexpected error due write to $filename");
        }
    }

    public function isFile() {
        return is_file($this->getAbsolutePath());
    }

    protected function getAbsolutePath() {
        $urlpath = parse_url($this->uri, PHP_URL_PATH);
        return self::_absolutePath("{$this->basedir}/$urlpath");
    }

    static protected function _absolutePath($relpath) {
        $relpath = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $relpath);
        $bits = explode(DIRECTORY_SEPARATOR, $relpath);
        $parts = array_merge(array(
                $bits[0]),
                array_filter(array_slice($bits, 1), 'strlen'));
        $absolutes = array();
        foreach ($parts as $part) {
            if ('.' == $part) continue;
            if ('..' == $part) {
                array_pop($absolutes);
            } else {
                $absolutes[] = $part;
            }
        }
        return implode(DIRECTORY_SEPARATOR, $absolutes);
    }

}


$fireputService = new FireputService();
$fireputService->handleHttpRequest();
