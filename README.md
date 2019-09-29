# CenterWebSocket
PHP WebSocket消息转发中心

# 主要功能：
* 任意用户间通讯 
* 用户与服务器通讯  
* 订阅指定用户的消息 

# 通讯协议：	
使用JSON	
* `uid`	用户ID，客户端自动生成
* `to`	 接收人，（用户ID、_server 服务器、_sub 订阅者、_all 所有用户、_type@类型名称）
* `cmd` 	命令
* `data` 	数据内容
* `time`	服务器时间

# 服务器内置命令：	
* `now 	查询当前时间
* `get_user_list` 	获取用户列表（type 用户类型）
* `get_user_count` 	获取用户数量（type 用户类型）
* `heart` 	心跳
* `register` 	注册（uid 用户ID，type 用户类型，name 名称）
* `set_config` 	更新配置 （config）
* `get_config` 	获取配置 （key，uid 用户ID）
* `get_current_user` 	获取当前用户
* `get_user` 	获取用户信息（uid 用户ID）
* `sub_user`	订阅指定用户（uid：用户ID、_server、_type@类型名称）
* `sub_user_del`	取消订阅指定用户

# 用户信息	
* `uid	用户ID
* `connect`	连接信息
* `connect_time`	连接时间
* `client`	客户端信息
* `config`	用户配置
* `last_time`	最后连接时间
* `sub_list`	订阅用户列表
* `type`	用户类型
* `name`	名称

# 服务器通知消息：	
* `user_login`	用户登陆
* `user_logout`	用户退出
	
# 服务器返回消息：	
* `server_error`	服务器错误
* `register_success`	注册成功

