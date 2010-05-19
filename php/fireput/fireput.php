<?php

define('FIREPUT_BASEDIR', dirname(dirname(__FILE__)));
define('FIREPUT_CSS_HEXCOLORS', true);
define('FIREPUT_CSS_LOWERCASEHEX', true);


class FireputService {
    const PROP_INDENT = "\t";
    const PROP_TEMPLATE = '%s: %s%s';
    const PROP_LINE_TEMPLATE = "\n%s;";

    protected $baseDir = FIREPUT_BASEDIR;
    protected $useLowercaseHex = FIREPUT_CSS_LOWERCASEHEX;

    public function __construct() {
    }

    public function onFreeEdit($href, $cssText) {
        $fileCss = $this->getFile($href);
        $fileCss->write($cssText);
    }

    public function onRemoveProperty($href, $propName, $ruleSelector) {
        error_log(__METHOD__."($href, $propName, $ruleSelector)");
        $fileCss = $this->getFile($href);
        $prevCssText = $fileCss->read();
        $cssText = $this->cssSetProperty($prevCssText, $propName, '', '', $ruleSelector);
        $fileCss->write($cssText);
    }

    public function onSetProperty($href, $propName, $propValue, $propPriority, $ruleSelector) {
        error_log(__METHOD__."($href, $propName, $propValue, $propPriority, $ruleSelector)");

        $fileCss = $this->getFile($href);
        $prevCssText = $fileCss->read();
        $cssText = $this->cssSetProperty($prevCssText, $propName, $propValue, $propPriority, $ruleSelector);
        $fileCss->write($cssText);
    }

    public function onInsertRule($href, $ruleIndex, $ruleCssText) {
        error_log(__METHOD__."($href, $ruleIndex, $ruleCssText)");

        $fileCss = $this->getFile($href);
        $prevCssText = $fileCss->read();
        $cssRules = $this->cssSplitRules($prevCssText);
        array_splice($cssRules, $ruleIndex, 0, array($ruleCssText));
        $cssText = implode('', $cssRules);
        $fileCss->write($cssText);
    }

    public function onDeleteRule($href, $ruleIndex) {
        error_log(__METHOD__."($href, $ruleIndex)");

        $fileCss = $this->getFile($href);
        $prevCssText = $fileCss->read();
        $cssRules = $this->cssSplitRules($prevCssText);
        unset($cssRules[$ruleIndex]);
        $cssText = implode('', $cssRules);
        $fileCss->write($cssText);
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
            $q = new QueryArray($_POST);
            switch ($q->get('subType')) {
                case 'freeEdit':
                    $this->onFreeEdit($q->get('href'), $q->get('cssText'));
                    break;
                case 'setProperty':
                    $this->onSetProperty($q->get('href'),
                            $q->get('propName'), $q->get('propValue'), $q->get('propPriority'),
                            $q->get('ruleSelector'));
                    break;
                case 'removeProperty':
                    $this->onRemoveProperty($q->get('href'), $q->get('propName'), $q->get('ruleSelector'));
                    break;
                case 'deleteRule':
                    $this->onDeleteRule($q->get('href'), $q->get('ruleIndex'));
                    break;
                case 'insertRule':
                    $this->onInsertRule($q->get('href'), $q->get('ruleIndex'), $q->get('cssText'));
                    break;
                default : error_log("Unknown message " . var_export($q, true)); break;
            }
            $location = HttpGetRequestedUri();
            return HttpResponseSeeOther($location);
        } else { //if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            return HttpResponseOk('Ok');
        }
    }

    public function cssSetProperty($prevCssText, $propName, $propValue, $propPriority, $ruleSelector) {
        if ($this->useLowercaseHex)
            $propValue = preg_replace('/#[[:xdigit:]]+/ei', 'strtolower("$0")', $propValue);
        $r = '#';
        $ruleSelectorR = preg_replace('#[[:space:]]+#', '[[:space:]]+', preg_quote($ruleSelector, $r));
        if (preg_match("{$r}{$ruleSelectorR}[[:space:]]*{([^}]*?)}{$r}s", $prevCssText, $matches,  PREG_OFFSET_CAPTURE)) {
            list($_, list($prevRuleCssText, $ruleStart)) = $matches;
            $propNameR = preg_quote($propName, $r);
            $propText = sprintf(self::PROP_TEMPLATE, $propName, $propValue,
                    $propPriority ? " $propPriority" : '');

            if (preg_match("${r}(^|;)([[:space:]]*)({$propNameR}[[:space:]]*:[^;]+?)(;|$){$r}s", $prevRuleCssText, $matches)) {
                list($all, $prefix, $indent, $prevPropText, $suffix) = $matches;
                if ($propValue) {
                    # set existing property
                    if (!$prefix) $indent = self::PROP_INDENT;
                    $ruleCssText = str_replace($all, "{$prefix}{$indent}{$propText}{$suffix}", $prevRuleCssText);
                } else {
                    # delete existing property
                    $ruleCssText = str_replace($all, "{$prefix}", $prevRuleCssText);
                }
            } else {
                if ($propValue) {
                    # add new property
                    $ruleCssText = rtrim($prevRuleCssText) . sprintf(self::PROP_LINE_TEMPLATE, self::PROP_INDENT . $propText);
                } else {
                    # delete new property
                    # it's not exists! do nothing.
                    $ruleCssText = $prevRuleCssText;
                }
            }

            $cssText = substr_replace($prevCssText, $ruleCssText, $ruleStart, strlen($prevRuleCssText));
        } else {
            throw new Exception("Rule '$ruleSelector' not found at the '$href'");
        }
        return $cssText;
    }

    public function cssSplitRules($cssText) {
        throw new Exception("Not implemented");
    }

    protected  function getFile($uri) {
        $file = new FileResource($uri, $this->baseDir);
        return $file;
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
        return getAbsolutePath("{$this->basedir}/$urlpath");
    }
}

function checkIsWriteableCss($cssFilename) {
    if (!is_file($cssFilename)) throw new Exception(
        "File not exists $cssFilename");
    if (!is_writable(cssFilename)) throw new Exception(
        "Can not write to cssFilename");
}

function getAbsolutePath($relpath) {
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


class QueryArray {
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

function HttpGetRequestedUri() {
    $schema = (!isset($_SERVER['HTTPS']) || strtolower($_SERVER['HTTPS']) != 'on')
            ? "http"
            : "https";
    $host = $_SERVER['HTTP_HOST'];
    $port = ($_SERVER['SERVER_PORT'] != '80' ? ":{$_SERVER['SERVER_PORT']}" : '');
    $requestUri = $_SERVER['REQUEST_URI'];
    return "$schema://$host$port$requestUri";
}

function HttpResponseOk($message) {
    $status = "200 OK";
    return HttpResponse($status, $message);
}

function HttpResponseBadRequest($reason) {
    $status = "400 Bad Request";
    $message = "$status: $reason";
    return HttpResponse($status, $message);
}

function HttpResponseNotFound($resource) {
    $status = "404 Not Found";
    $message = "$status: $resource";
    return HttpResponse($status, $message);
}

function HttpResponseInternalServerError($reason) {
    $status = "500 Internal Server Error";
    $message = "$status: $reason";
    return HttpResponse($status, $message);
}

function HttpResponseSeeOther($location) {
    $status = "302 Found";
    $message = $location;
    header("Location: $location");
    return HttpResponse($status, $message);
}

function HttpResponse($status, $message=null) {
    if (substr(php_sapi_name(), 0, 3) == 'cgi') {
        header("Status: $status", TRUE);
    } else {
        header($_SERVER['SERVER_PROTOCOL'] . ' ' . $status);
        header("Status: $status", TRUE);
    }
    if (isset($message)) echo $message;
}


$FireputService = new FireputService();
$FireputService->handleHttpRequest();
