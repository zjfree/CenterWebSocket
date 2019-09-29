<?php

// 系统入口
date_default_timezone_set("PRC");
error_reporting(E_ALL & ~E_NOTICE);

$work_path = dirname(__FILE__);   
chdir($work_path);

use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;
use \Workerman\Lib\Timer;

require_once __DIR__ . './Workerman/Autoloader.php';

class Client
{
    public static $config = [
        'server' => 'ws://127.0.0.1:9900',
        'uid'    => 'ws_client',
        'name'   => 'PHP客户端01',
    ];

    public static $conn = null;

    public static $is_connect = false;

    // 发送消息
    public static function send($to, $cmd, $data = null)
    {
        if (!self::$is_connect)
        {
            return;
        }

        $item = [
            'to'   => $to,
            'cmd'  => $cmd,
            'data' => $data,
        ];

        self::$conn -> send(json_encode($item));

        echo date('Y-m-d H:i:s') . ' SEND ' . $to . ':' . $cmd . PHP_EOL;
    }

    // 注册
    public static function register()
    {
        self::send('_server', 'register', [
            'uid'  => self::$config['uid'],
            'name' => self::$config['name'],
            'type' => 'php_client',
        ]);
    }

    // 接收信息解析
    public static function receiveParse($data)
    {
        $item = json_decode($data, true);
        if (empty($item))
        {
            echo '解析失败：' + $data;
            return;
        }

        echo $item['time'] . ' ' . $item['uid'] . ':' . $item['cmd'] . ',' . (@$item['data'] ? json_encode($item['data'], 384) : '') . PHP_EOL;
    }
}

Worker::$logFile = './log/workerman.log';

$worker = new Worker();

$worker->name = 'WebsocketClient';

$worker->onWorkerStart = function($worker){

    // 发送数据
    Timer::add(5, function()
    {
		Client::send('_sub', 'hello', date('Y-m-d H:i:s'));
    });
    
    // 发送心跳
    Timer::add(60, function()
    {
		Client::send('_server', 'heart');
    });
    
    $conn = new AsyncTcpConnection(Client::$config['server']);
    Client::$conn = $conn;
    
    $conn->onConnect = function($conn) {
        Client::$is_connect = true;
        Client::register();
        echo 'connect' . PHP_EOL;
    };

    $conn->onMessage = function($conn, $data) {
        Client::receiveParse($data);
    };

    $conn->onClose = function($conn) {
        Client::$is_connect = false;
        echo 'connect close' . PHP_EOL;
        $conn->reConnect(5);
    };
    
    $conn->onError = function($conn, $code, $msg) {
        echo 'ERROR:[' . $code . ']' . $msg . PHP_EOL;
    };

    $conn->connect();
};

Worker::runAll();