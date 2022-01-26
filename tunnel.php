<?php

/**
 * 持久连接
 * 
 * 启用该选项会极大提升访问速度
 * 需服务器支持
 */
define('USE_PRESISTENT_CONNECTION', true);

/**
 * 系统保护
 * 
 * 只允许连接到localhost
 * 不允许使用root用户
 */
define('SYSTEM_PROTECT', true);

/**
 * 审计日志
 * 
 * 日志将保存在 logs 目录下
 * 并以日期/用户名做分类
 */
define('AUDIT_LOG', true);

header("Content-Type: text/plain; charset=x-user-defined");
error_reporting(0);
set_time_limit(0);

/**
 * @param int $num
 * @return string
 */
function GetLongBinary($num)
{
    return pack("N", $num);
}

/**
 * @param int $num
 * @return string
 */
function GetShortBinary($num)
{
    return pack("n", $num);
}

/**
 * @param int $count
 * @return string
 */
function GetDummy($count)
{
    return str_repeat("\x00", $count);
}

/**
 * @param string $val
 * @return string
 */
function GetBlock($val)
{
    $len = strlen($val);

    if ($len < 254) {
        return chr($len) . $val;
    }
    else {
        return "\xFE" . GetLongBinary($len) . $val;
    }
}

/**
 * @param int $errno
 */
function EchoHeader($errno)
{
    $str  = GetLongBinary(1111);
    $str .= GetShortBinary(202);
    $str .= GetLongBinary($errno);
    $str .= GetDummy(6);
    echo $str;
}

/**
 * @param mysqli $conn
 */
function EchoConnInfo($conn)
{
    $str  = GetBlock($conn->host_info);
    $str .= GetBlock($conn->protocol_version);
    $str .= GetBlock($conn->server_info);
    echo $str;
}

/**
 * @param int $errno
 * @param int $affectrows
 * @param int $insertid
 * @param int $numfields
 * @param int $numrows
 */
function EchoResultSetHeader($errno, $affectrows, $insertid, $numfields, $numrows)
{
    $str  = GetLongBinary($errno);
    $str .= GetLongBinary($affectrows);
    $str .= GetLongBinary($insertid);
    $str .= GetLongBinary($numfields);
    $str .= GetLongBinary($numrows);
    $str .= GetDummy(12);
    echo $str;
}

/**
 * @param mysqli_result $res
 * @param int $numfields
 */
function EchoFieldsHeader($res, $numfields)
{
    $str = "";

    for ($i = 0; $i < $numfields; $i++) {
        $finfo = $res->fetch_field_direct($i);

        $str .= GetBlock($finfo->name);
        $str .= GetBlock($finfo->table);
        $str .= GetLongBinary($finfo->type);
        $str .= GetLongBinary($finfo->flags);
        $str .= GetLongBinary($finfo->length);
    }

    echo $str;
}

/**
 * @param mysqli_result $res
 * @param int $numfields
 * @param int $numrows
 */
function EchoData($res, $numfields, $numrows)
{
    for ($i = 0; $i < $numrows; $i++) {
        $str = "";
        $row = $res->fetch_row();

        foreach ($row as $each) {
            if (is_null($each)) {
                $str .= "\xFF";
            }
            else {
                $str .= GetBlock($each);
            }
        }

        echo $str;
    }
}

/////////////////////////////////////////////////////////////////////////////

$host = filter_input(INPUT_POST, 'host', FILTER_DEFAULT);
$port = filter_input(INPUT_POST, 'port', FILTER_DEFAULT);
$username = filter_input(INPUT_POST, 'login', FILTER_DEFAULT);
$password = filter_input(INPUT_POST, 'password', FILTER_DEFAULT);
$database = filter_input(INPUT_POST, 'db', FILTER_DEFAULT);

$action = filter_input(INPUT_POST, 'actn', FILTER_DEFAULT);
$query = filter_inpuT(INPUT_POST, 'q', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);

$encodeBase64 = filter_input(INPUT_POST, 'encodeBase64', FILTER_VALIDATE_BOOLEAN);

if (empty($action) || empty($host) || empty($port) || empty($username)) {
    EchoHeader(202);
    echo GetBlock("invalid parameters");
    exit();
}

if (!function_exists("mysqli_connect")) {
    EchoHeader(203);
    echo GetBlock("MySQL not supported on the server");
    exit();
}

if ($encodeBase64) {
    foreach ($query as $key => $value) {
        $query[$key] = base64_decode($value);
    }
}

if (SYSTEM_PROTECT) {
    if ($host !== 'localhost') {
        EchoHeader(401);
        echo GetBlock("invalid hostname");
        exit();
    }

    if ($username === 'root') {
        EchoHeader(401);
        echo GetBlock("invalid username");
        exit();
    }
}

if (AUDIT_LOG) {
    $remote_ip = $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'];
    $remote_port = $_SERVER['REMOTE_PORT'];

    $audit_log_dir = __DIR__ . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . date('Ym') . DIRECTORY_SEPARATOR;
    $audit_log_file = $audit_log_dir  . date('d') . ' - ' . $username . '.log';
    $audit_time = date('Y-m-d H:i:s');

    if (!is_dir($audit_log_dir)) {
        mkdir($audit_log_dir, 0775, TRUE);
    }

    if ($action === "Q") {
        $log = "{$audit_time} {$username}@{$remote_ip}:{$remote_port}" . PHP_EOL;

        foreach ($query as $each) {
			if ($each === "SET NAMES 'utf8mb4'") {
				continue;
			}
			
            $log .=  "  {$each}" . PHP_EOL;
        }

        $log .= PHP_EOL;

        file_put_contents($audit_log_file, $log, FILE_APPEND);
    }
}

if (USE_PRESISTENT_CONNECTION) {
    $host = "p:{$host}";
}

$conn = new mysqli($host, $username, $password, '', $port);

if ($conn->connect_errno) {
    EchoHeader($conn->connect_errno);
    echo GetBlock(mysqli_connect_error());
    exit();
}

$conn->set_charset('utf8mb4');

if (!empty($database)) {
    $conn->select_db($database);
}

if ($conn->errno) {
    EchoHeader($conn->connect_errno);
    echo GetBlock($conn->error);
    exit();
}

if ($action === 'C') {
    EchoHeader(0);
    EchoConnInfo($conn);
    exit();
}

if ($action === 'Q') {
    EchoHeader(0);

    foreach ($query as $key => $value) {
        if (empty($query)) {
            continue;
        }

        $result = $conn->query($value);

        if ($conn->errno) {
            EchoResultSetHeader($conn->errno, 0, 0, 0, 0);
            echo GetBlock($conn->error);
        }
        else {
            $affectRows = $conn->affected_rows;
            $insertId = $conn->insert_id;

            $numFields = $result->field_count;
            $numRows = $result->num_rows;

            EchoResultSetHeader(0, $affectRows, $insertId, $numFields, $numRows);

            if ($numFields > 0) {
                EchoFieldsHeader($result, $numFields);
                EchoData($result, $numFields, $numRows);
            }
            else {
                echo GetBlock($conn->info);
            }
        }

        if ($key !== array_key_last($query)) {
            echo "\x01";
        }
        else {
            echo "\x00";
        }

        if (!is_bool($result)) {
            $result->free();
        }
    }
}
