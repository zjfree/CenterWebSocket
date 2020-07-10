<?php

// 系统入口
date_default_timezone_set("PRC");
error_reporting(E_ALL & ~E_NOTICE);

$work_path = dirname(__FILE__);   
chdir($work_path);

/**
 * 系统
 */
class Sys
{
	public static $config = [
		'port' => 9900,
	];
	
	public static $limit_user_list = [
		'_server',
		'_all',
		'_sub',
	];

	public static $worker = null;

	// 用户列表
	public static $user_list = [];

	// 写入日志
	public static function addLog($content, $type = 'log')
	{
		$file = './log/' . $type . date('_Ymd') . '.log';

		$str = date('Y-m-d H:i:s ') . $content . PHP_EOL;
		@file_put_contents($file, $str, FILE_APPEND);
		
		echo $str;
	}

	// 当前时间
	public static function now()
	{
		return date('Y-m-d H:i:s');
	}

	// 接收内容解析
	public static function receiveParse(&$conn, $str)
	{
		$receive = json_decode($str, true);
		if (empty($receive) || !is_array($receive))
		{
			Sys::addLog('ERROR:接收内容JSON解析失败！' . $str);
			Sys::serverError($conn, 'JSON解析失败！');

			return false;
		}

		$receive['uid']  = @$conn -> uid ?: '';
		$receive['cmd']  = @$receive['cmd'] ?: '';
		$receive['to']   = @$receive['to'] ?: '_server';
		$receive['time'] = Sys::now();

		$uid = $receive['uid'];
		
		// 获取当前用户
		$cur_user = null;
		if (empty($uid))
		{
			// 用户未注册
			if ($receive['cmd'] != 'register')
			{
				return false;
			}
		}
		else
		{
			if (!isset(self::$user_list[$uid]))
			{
				Sys::addLog('ERROR:用户[' . $uid . ']不存在！' . $str);
				return false;
			}

			self::$user_list[$uid]['last_time'] = Sys::now();
			$cur_user = self::$user_list[$uid];
		}

		if ($receive['to'] == '_server')
		{
			// 服务器处理消息
			$cmd = 'cmd_' . $receive['cmd'];
			if ($receive['cmd'] != 'heart')
			{
				Sys::addLog('SERVER>' . $uid . ':' . $receive['cmd']);
			}

			if (is_callable(['Sys', $cmd]))
			{
				call_user_func(['Sys', $cmd], $conn, $receive);
			}
			else
			{
				Sys::addLog('ERROR:cmd不存在！' . $receive['cmd']);
				Sys::serverError($conn, 'cmd不存在');
			}
		}
		else if ($receive['to'] == '_all')
		{
			// 发送给所有人
			foreach(self::$user_list as $user)
			{
				if ($user['uid'] != $uid)
				{
					self::send($user['conn'], $receive);
				}
			}
		}
		else if ($receive['to'] == '_sub')
		{
			// 发送给订阅用户
			$uid_list = self::getSubUserList($uid, $cur_user['type']);
			foreach($uid_list as $uid)
			{
				if (!empty(self::$user_list[$uid]))
				{
					self::send(self::$user_list[$uid]['conn'], $receive);
				}
			}
		}
		else if (strpos($receive['to'], '_type@') === 0)
		{
			// 发送给指定用户类型
			$user_type = substr($receive['to'], 6);
			foreach(self::$user_list as $user)
			{
				if ($user['uid'] != $uid && $user['type'] == $user_type)
				{
					self::send($user['conn'], $receive);
				}
			}
		}
		else if (isset(self::$user_list[$receive['to']]))
		{
			// 发送给指定用户
			$user = self::$user_list[$receive['to']];
			self::send($user['conn'], $receive);
		}
		else
		{
			Sys::addLog('ERROR:接收人不存在！' . $receive['to']);
			Sys::serverError($conn, '接收人不存在');

			return false;
		}

		return true;
	}

	// 发送消息
	public static function send($conn, $data)
	{
		$data['time'] = Sys::now();
		if (empty($data['to']))
		{
			$data['to'] = @$conn -> uid ?: '';
		}

		$data = json_encode($data, JSON_UNESCAPED_UNICODE);

		$conn -> send($data);

		return true;
	}

	// 服务器返回错误
	public static function serverError($conn, $str, $error_code = -1)
	{
		Sys::send($conn, [
			'uid'  => '_server',
			'cmd'  => 'server_error',
			'data' => $str,
			'error_code' => $error_code,
		]);
	}

	// 服务器通知
	public static function serverSend($cmd, $data = null)
	{
		foreach(self::$user_list as $user)
		{
			self::send($user['conn'], [
				'uid'  => '_server',
				'to'   => '_all',
				'cmd'  => $cmd,
				'data' => $data,
			]);
		}
	}

	// 服务器订阅发送
	public static function serverSubSend($cmd, $data = null)
	{
		$uid_list = self::getSubUserList('_server');
		foreach($uid_list as $uid)
		{
			if (!empty(self::$user_list[$uid]))
			{
				self::send(self::$user_list[$uid]['conn'], [
					'uid'  => '_server',
					'to'   => '_sub',
					'cmd'  => $cmd,
					'data' => $data,
				]);
			}
		}
	}

	// 用户断开
	public static function userClose($conn)
	{
		if (isset($conn -> uid))
		{
			$uid = $conn -> uid;

			self::addLog('用户断开：' . $uid);
			unset(self::$user_list[$uid]);
			
			Sys::serverSubSend('user_logout', $uid);
		}
	}

	// 删除列表中指定值
	public static function listDelItem($list, $val)
	{
		$new_list = [];
		foreach ($list as $r)
		{
			if ($r != $val)
			{
				$new_list[] = $r;
			}
		}

		return $new_list;
	}

	// 获取订阅用户UID列表
	private static function getSubUserList($uid, $type = '')
	{
		$uid_list = [];
		foreach (self::$user_list as $user)
		{
			if (in_array($uid, $user['sub_list']) || in_array('_type@' . $type, $user['sub_list']))
			{
				$uid_list[] = $user['uid'];
			}
		}

		return $uid_list;
	}

	// 用户注册
	public static function cmd_now($conn, $receive)
	{
		Sys::send($conn, [
			'uid'  => '_server',
			'cmd'  => 'now',
			'data' => Sys::now(),
			'cmdIndex' => @$receive['cmdIndex'] ?: 0,
		]);
	}

	// 用户注册
	public static function cmd_register($conn, $receive)
	{
		$uid = @$receive['data']['uid'] ?: '';
		$name = @$receive['data']['name'] ?: '';
		$type = @$receive['data']['type'] ?: 'user';

		if (empty($uid))
		{
			Sys::serverError($conn, '注册失败！uid为空');
			return;
		}
		
		if (in_array($uid, Sys::$limit_user_list) || strpos($uid, '_type@') === 0 || strlen($uid) > 36)
		{
			Sys::serverError($conn, '注册失败！uid非法');
			return;
		}

		if (in_array($uid, array_keys(Sys::$user_list)) && $uid != (@$conn -> uid ?: ''))
		{
			Sys::$user_list[$uid]['conn'] -> close();
			Sys::addLog("[$uid] reconnect");
		}

		$user = @Sys::$user_list[$uid] ?: [
			'config'   => [],
			'sub_list' => [],
		];

		$user['uid']       = $uid;
		$user['conn']      = $conn;
		$user['name']      = $name;
		$user['type']      = $type;
		$user['conn_time'] = Sys::now();
		$user['client']    = $conn->getRemoteIp() . ':' . $conn->getRemotePort();
		$user['last_time'] = Sys::now();

		$conn -> uid = $uid;
		Sys::$user_list[$uid] = $user;

		unset($user['conn']);

		Sys::send($conn, [
			'uid'  => '_server',
			'cmd'  => 'register_success',
			'data' => $user,
			'cmdIndex' => @$receive['cmdIndex'] ?: 0,
		]);

		Sys::serverSubSend('user_login', [
			'uid'  => $uid,
			'name' => $name,
			'type' => $type,
			'conn_time' => $user['conn_time'],
		]);

		Sys::addLog('用户注册：' . $uid);
	}
	
	// 获取用户列表
	public static function cmd_get_user_list($conn, $receive)
	{
		$type = @$receive['data']['type'] ?: '';

		$user_list = [];
		foreach (self::$user_list as $r)
		{
			if ($type == '' || $r['type'] == $type)
			{
				$user_list[] = [
					'uid'       => $r['uid'],
					'name'      => $r['name'],
					'type'      => $r['type'],
					'conn_time' => $r['conn_time'],
					'client'    => $r['client'],
					'last_time' => $r['last_time'],
				];
			}
		}

		Sys::send($conn, [
			'uid'  => '_server',
			'cmd'  => 'get_user_list',
			'data' => $user_list,
			'cmdIndex' => @$receive['cmdIndex'] ?: 0,
		]);
	}

	// 获取用户数量
	public static function cmd_get_user_count($conn, $receive)
	{
		Sys::send($conn, [
			'uid'  => '_server',
			'cmd'  => 'get_user_count',
			'data' => count(self::$user_list),
			'cmdIndex' => @$receive['cmdIndex'] ?: 0,
		]);
	}
	
	// 心跳
	public static function cmd_heart($conn, $receive)
	{
	}
	
	// 设置配置信息 （当前用户）
	public static function cmd_set_config($conn, $receive)
	{
		$config = self::$user_list[$conn->uid]['config'];

		$config = array_merge($config, $receive['data']);

		self::$user_list[$conn->uid]['config'] = $config;

		Sys::send($conn, [
			'uid'  => '_server',
			'cmd'  => 'set_config',
			'data' => true,
			'cmdIndex' => @$receive['cmdIndex'] ?: 0,
		]);
	}
	
	// 获取配置信息
	public static function cmd_get_config($conn, $receive)
	{
		$uid = @$receive['data']['uid'] ?: $conn->uid;
		$key = $receive['data']['key'] ?: '';
		$val = @self::$user_list[$uid]['config'][$key] ?: null;

		Sys::send($conn, [
			'uid'  => '_server',
			'cmd'  => 'get_config',
			'data' => $val,
			'cmdIndex' => @$receive['cmdIndex'] ?: 0,
		]);
	}
	
	// 获取当前用户
	public static function cmd_get_current_user($conn, $receive)
	{
		$user = self::$user_list[$conn->uid];

		unset($user['conn']);

		Sys::send($conn, [
			'uid'  => '_server',
			'cmd'  => 'get_current_user',
			'data' => $user,
			'cmdIndex' => @$receive['cmdIndex'] ?: 0,
		]);
	}
	
	// 获取用户信息
	public static function cmd_get_user($conn, $receive)
	{
		$uid = @$receive['data']['uid'] ?: $conn->uid;
		$user = self::$user_list[$uid];

		unset($user['conn']);

		Sys::send($conn, [
			'uid'  => '_server',
			'cmd'  => 'get_user',
			'data' => $user,
			'cmdIndex' => @$receive['cmdIndex'] ?: 0,
		]);
	}
	
	// 订阅用户消息
	public static function cmd_sub_user($conn, $receive)
	{
		$sub_uid = @$receive['data']['uid'] ?: '';
		
		$sub_list = self::$user_list[$conn -> uid]['sub_list'];
		$sub_list[] = $sub_uid;
		
		$sub_list = array_unique($sub_list);

		self::$user_list[$conn -> uid]['sub_list'] = $sub_list;

		Sys::send($conn, [
			'uid'  => '_server',
			'cmd'  => 'sub_user',
			'data' => true,
			'cmdIndex' => @$receive['cmdIndex'] ?: 0,
		]);
	}
	
	// 取消订阅用户消息
	public static function cmd_sub_user_del($conn, $receive)
	{
		$sub_uid = @$receive['data']['uid'] ?: '';
		
		$sub_list = self::$user_list[$conn -> uid]['sub_list'];
		$sub_list = self::listDelItem($sub_list, $sub_uid);
		self::$user_list[$conn -> uid]['sub_list'] = $sub_list;

		Sys::send($conn, [
			'uid'  => '_server',
			'cmd'  => 'sub_user_del',
			'data' => true,
			'cmdIndex' => @$receive['cmdIndex'] ?: 0,
		]);
	}
	
	// 获取所有连接
	public static function cmd_get_conn_list($conn, $receive)
	{
		$conn_list = [];
		foreach (self::$worker -> connections as $client_conn)
		{
			$conn_list[] = [
				'ip'   => $client_conn->getRemoteIp(),
				'port' => $client_conn->getRemotePort(),
				'uid'  => @$client_conn -> uid ?: '',
				'time' => @$client_conn -> connectTime ?: '',
			];
		}

		Sys::send($conn, [
			'uid'  => '_server',
			'cmd'  => 'get_conn_list',
			'data' => $conn_list,
			'cmdIndex' => @$receive['cmdIndex'] ?: 0,
		]);
	}
	
	// 获取当前用户
	public static function cmd_get_sub_list($conn, $receive)
	{
		$user = self::$user_list[$conn->uid];

		$uid_list = self::getSubUserList($conn->uid, $user['type']);

		Sys::send($conn, [
			'uid'  => '_server',
			'cmd'  => 'get_sub_list',
			'data' => implode(',', $uid_list),
			'cmdIndex' => @$receive['cmdIndex'] ?: 0,
		]);
	}
	
}

/*
webSocket
*/

use \Workerman\Worker;
use \Workerman\Lib\Timer;

require_once './Workerman/Autoloader.php';

Worker::$logFile = './log/workerman.log';

// 初始化一个worker容器，监听端口
$worker = new Worker('websocket://0.0.0.0:' . Sys::$config['port']);
Sys::$worker = &$worker;

$worker->name = 'WebsocketWorker';

// 进程数设置为1，windows服务器只能使用1个进程
$worker->count = 1;

// 服务启动
$worker->onWorkerStart = function($worker)
{
	Sys::addLog('SERVER start!');
	
	// 每60秒执行一次
	/*
    Timer::add(60, function()
    {
		Sys::serverSend('time', Sys::now());
	});
	*/
};

// 服务终止
$worker->onWorkerStop = function($worker)
{
	Sys::addLog('SERVER stop!');
};

// 建立连接
$worker->onConnect = function($conn)use($worker)
{
	$conn -> connectTime = date('Y-m-d H:i:s');
    Sys::addLog("connect: " . $conn->getRemoteIp() . ':' . $conn->getRemotePort());
};

// 当有客户端发来消息时执行的回调函数
$worker->onMessage = function($conn, $receive)use($worker)
{
	Sys::receiveParse($conn, $receive);
};

// 连接断开
$worker->onClose = function($conn)use($worker)
{
	Sys::addLog("disconnect: " . $conn->getRemoteIp() . ':' . $conn->getRemotePort());
	Sys::userClose($conn);
	
	$conn->destroy();
};

// 发生错误时
$worker->onError = function($conn, $code, $msg)
{
	$str = isset($conn->uid) ? ('[' . $conn->uid . '] ') : '';
	$str .= 'ERROR:' . $code . ', ' . $msg;

	Sys::addLog($str);
};

Worker::runAll();