<?php

function hConfig($key)
{
    return getenv($key, '');
//    return isset($_SERVER[$key])?$_SERVER[$key]:'';
}

function hLog($content)
{
    global $is_debug;
    if (true) {
        error_log(date('Y-m-d H:i:s') . ' - ' . $content . PHP_EOL, 3, '/var/xhprof/log/error.log');
    }
}

$is_debug = hConfig('XHGUI_CONFIG_DEBUG');
if ($is_debug) {
    ini_set('display_errors', 1);
}
if (!hConfig('XHGUI_CONFIG_SHOULD_RUN')) {
    hLog('xhgui 关闭状态，不采集！ ');
    return;
}

$extension = hConfig('XHGUI_CONFIG_EXTENSION');
if (!$extension) {
    hLog('xhgui 环境初始化错误，没有设置要使用的扩展！ ');
    return;
}
$percent = hConfig('XHGUI_CONFIG_PERCENT');
$xhMode = hConfig('XHGUI_CONFIG_MODE');

/**
 * header采集
 *
 * @return bool
 */
function headerCollect()
{
    $headerKey = 'HTTP_X_COLLECT'; // 在 $_SERVER 中，Header 名称需转换格式
    return isset($_SERVER[$headerKey]) && strtolower($_SERVER[$headerKey]) == 'true';
}

/**
 * 百分百采集
 * @return bool
 */
function percentCollect($percent)
{
    return rand(1, 100) > $percent;
}

if ($xhMode == 1) {
    // 是否拦截采集
    if (!headerCollect() && percentCollect($percent)) {
        //hLog('xhgui 百分比采集忽略！ ');
        return;
    }
}


if ($xhMode == 2) {
    $enblaeXhprof = isset($_REQUEST['_xhprof']) || isset($_COOKIE['_xhprof']);
    if (!$enblaeXhprof) {
        return;
    }
}

if (!extension_loaded('xhprof') && !extension_loaded('uprofiler') && !extension_loaded('tideways') && !extension_loaded('tideways_xhprof')) {
    hLog('xhgui - either extension xhprof, uprofiler or tideways must be loaded');
    return;
}

$dir = dirname(__DIR__);
$simpleUrlProcess = function_exists("_XhguiHeader_SimpleUrl") ? function ($data) {
    return call_user_func('_XhguiHeader_SimpleUrl', $data);
} : function ($url) {
    return preg_replace('/\=\d+/', '', $url);
};

/**
 * @var $saverHander callable()
 */
$saverHandler = function_exists("_XhguiHeader_Saver") ? function ($data) {
    return call_user_func('_XhguiHeader_Saver', $data);
} : function ($data) {
    $saveUrl = hConfig('XHGUI_CONFIG_SAVER_URL') . '?token=' . hConfig('XHGUI_UPLOAD_TOKEN');
    $timeout = hConfig('XHGUI_CONFIG_SAVER_URL_TIME_OUT');
    if ($saveUrl) {
        $options = array(
            'http' => array(
                'header' => "Content-type: application/json",
                'method' => 'POST',
                'content' => json_encode($data, true),
                // 'content' => json_encode(['dd'=>1]),
                'timeout' => $timeout ? $timeout : 4,
            ),
        );
        $context = stream_context_create($options);
        $result = file_get_contents($saveUrl, false, $context);
        if ($result === false) {
            return [];
        }
        return json_decode($result, true);
    } else {
        hLog('xhgui 没有配置采集地址，请配置环境变量 ');
    }

};


$filterPath = hConfig('XHGUI_CONFIG_FILTER_PATH') ? explode(',', hConfig('XHGUI_CONFIG_FILTER_PATH')) : [];
if (is_array($filterPath) && in_array($_SERVER['DOCUMENT_ROOT'], $filterPath)) {
    return;
}


if (!isset($_SERVER['REQUEST_TIME_FLOAT'])) {
    $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
}

$extension = hConfig('XHGUI_CONFIG_EXTENSION');
if ($extension == 'uprofiler' && extension_loaded('uprofiler')) {
    uprofiler_enable(UPROFILER_FLAGS_CPU | UPROFILER_FLAGS_MEMORY);
} else if ($extension == 'tideways_xhprof' && extension_loaded('tideways_xhprof')) {
    tideways_xhprof_enable(TIDEWAYS_XHPROF_FLAGS_MEMORY | TIDEWAYS_XHPROF_FLAGS_MEMORY_MU | TIDEWAYS_XHPROF_FLAGS_MEMORY_PMU | TIDEWAYS_XHPROF_FLAGS_CPU);
} else if ($extension == 'tideways' && extension_loaded('tideways')) {
    tideways_enable(TIDEWAYS_FLAGS_CPU | TIDEWAYS_FLAGS_MEMORY);
    tideways_span_create('sql');
} else if (function_exists('xhprof_enable')) {
    if (PHP_MAJOR_VERSION == 5 && PHP_MINOR_VERSION > 4) {
        xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY | XHPROF_FLAGS_NO_BUILTINS);
    } else {
        xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY);
    }
} else {
    hLog('xhgui 你指定扩展不支持！ ');
    return;
}

register_shutdown_function(
    function () use ($simpleUrlProcess, $saverHandler) {
        $extension = hConfig('XHGUI_CONFIG_EXTENSION');
        if ($extension == 'uprofiler' && extension_loaded('uprofiler')) {
            $data['profile'] = uprofiler_disable();
        } else if ($extension == 'tideways_xhprof' && extension_loaded('tideways_xhprof')) {
            $data['profile'] = tideways_xhprof_disable();
        } else if ($extension == 'tideways' && extension_loaded('tideways')) {
            $data['profile'] = tideways_disable();
            $sqlData = tideways_get_spans();
            $data['sql'] = array();
            if (isset($sqlData[1])) {
                foreach ($sqlData as $val) {
                    if (isset($val['n']) && $val['n'] === 'sql' && isset($val['a']) && isset($val['a']['sql'])) {
                        $_time_tmp = (isset($val['b'][0]) && isset($val['e'][0])) ? ($val['e'][0] - $val['b'][0]) : 0;
                        if (!empty($val['a']['sql'])) {
                            $data['sql'][] = [
                                'time' => $_time_tmp,
                                'sql' => $val['a']['sql']
                            ];
                        }
                    }
                }
            }
        } else {
            $data['profile'] = xhprof_disable();
        }

        // ignore_user_abort(true) allows your PHP script to continue executing, even if the user has terminated their request.
        // Further Reading: http://blog.preinheimer.com/index.php?/archives/248-When-does-a-user-abort.html
        // flush() asks PHP to send any data remaining in the output buffers. This is normally done when the script completes, but
        // since we're delaying that a bit by dealing with the xhprof stuff, we'll do it now to avoid making the user wait.
        ignore_user_abort(true);
        flush();


        $uri = array_key_exists('REQUEST_URI', $_SERVER)
            ? $_SERVER['REQUEST_URI']
            : null;
        if (empty($uri) && isset($_SERVER['argv'])) {
            $cmd = basename($_SERVER['argv'][0]);
            $uri = $cmd . ' ' . implode(' ', array_slice($_SERVER['argv'], 1));
        }

        $time = array_key_exists('REQUEST_TIME', $_SERVER)
            ? $_SERVER['REQUEST_TIME']
            : time();
        $requestTimeFloat = explode('.', $_SERVER['REQUEST_TIME_FLOAT']);
        if (!isset($requestTimeFloat[1])) {
            $requestTimeFloat[1] = 0;
        }

        $requestTs = array('sec' => $time, 'usec' => 0);
        $requestTsMicro = array('sec' => $requestTimeFloat[0], 'usec' => $requestTimeFloat[1]);

        // 修改server_name
        $_SERVER['SERVER_NAME'] = !empty(getenv('XHGUI_CLIENT_NAME')) ? getenv('XHGUI_CLIENT_NAME') : $_SERVER['SERVER_NAME'];
        $data['meta'] = array(
            'url' => $uri,
            'SERVER' => $_SERVER,
            'get' => $_GET,
            'env' => $_ENV,
            'simple_url' => $simpleUrlProcess($uri),
            'request_ts' => $requestTs,
            'request_ts_micro' => $requestTsMicro,
            'request_date' => date('Y-m-d', $time),
        );

        try {
            //error_log(json_encode($data), 3, '/var/xhprof/log/xhprof.log');

            $result = $saverHandler($data);
            // hLog("saver result: ".json_encode($result));
        } catch (Exception $e) {
            hLog('xhgui - ' . $e->getMessage());
        }
    }
);