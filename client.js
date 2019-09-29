// webSocket 客户端
function WSClient(option)
{
    let defaultOption = {
        uid: 'web_' + Math.random().toString(36).substr(2).toUpperCase(),
        name:'',
        type:'web',
        server: 'ws://127.0.0.1:9900',
        receive:function(data){
            console.log(data);
        },
        error:function(err){
            console.error(err);
        },
        showInfo:function(str){
            console.info(str);
        },
    };

    this.option = $.extend(defaultOption, option);

    if (!this.option.server)
    {
        throw 'server is null';
    }
    
    this.ws = null;
    this.isConnect = false;
    this.closeTimeout = null;
    this.cmdIndex = 1;
    this.callbackList = [];

    let _this = this;

    // 连接
    this.connect = function(){
        try
        {
           this.ws = new WebSocket(this.option.server);
        }
        catch(ex)
        {
            this.option.error('连接失败：' + JSON.stringify(ex));
            return;
        }
            
        this.closeTimeout = window.setTimeout(function(){
            _this.ws.close();
        }, 3000);

        this.ws.onopen = function() {
            window.clearTimeout(_this.closeTimeout);
            _this.isConnect = true;
            _this.option.showInfo('连接成功');
            _this.send('_server', 'register', {
                uid:_this.option.uid,
                name:_this.option.name,
                type:_this.option.type,
            });
            
            // 心跳
            window.setInterval(function(){
                _this.send('_server', 'heart');
            }, 60000);
        };
        
        this.ws.onmessage = function(e) {
            let data = null;
            try
            {
                data = JSON.parse(e.data);
            }
            catch(ex)
            {
                _this.option.error('json 解析失败！' + e.data);
                return;
            }
            
            if (_this.callbackList[data.cmdIndex])
            {
                _this.callbackList[data.cmdIndex](data);
                _this.callbackList[data.cmdIndex] = null;
            }
            else
            {
                _this.option.receive(data);
            }
        };
        
        this.ws.onerror = function() {
            let str = JSON.stringify(arguments);
            _this.error('错误：' + str);
        };
        
        this.ws.onclose = function() {
            window.clearTimeout(_this.closeTimeout);
            _this.isConnect = false;
            window.setTimeout(function(){
                _this.connect();
            }, 1000);
            _this.option.showInfo('连接关闭');
        };
    };
    
    // 发送消息
    this.send = function(to, cmd, data, callback)
    {
        data = data || null;
        callback = callback || null;

        let arr = {
            to:to,
            cmd:cmd,
            cmdIndex:this.cmdIndex++,
            data:data,
        };

        if (callback)
        {
            this.callbackList[arr.cmdIndex] = callback;
        }

        this.option.showInfo('SEND: ' + to + ',' + cmd + ',' + JSON.stringify(data));

        if (this.isConnect)
        {
            this.ws.send(JSON.stringify(arr));
        }
    };

    this.connect();
}