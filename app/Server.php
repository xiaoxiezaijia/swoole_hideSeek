<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Manager\DataCenter;
use App\Manager\Logic;
use App\Manager\TaskManager;

class Server
{
    const HOST = '0.0.0.0';
    const PORT = 8811;
    const CONFIG = [
        'worker_num' => 4,
        'enable_static_handler' => true,
        'document_root' =>
            '/home/wwwroot/simple_game/public',
        'task_worker_num' => 4,
        'dispatch_mode' => 5, // bind 模式 绑定 worker
    ];
    const FRONT_PORT = 8812;
    const CLIENT_CODE_MATCH_PLAYER = 600;
    const CLIENT_CODE_START_ROOM = 601;
    const CLIENT_CODE_MOVE_PLAYER = 602;

    private $logic;

    private $ws;

    public function __construct()
    {
        $this->logic = new Logic();

        $this->ws = new \Swoole\WebSocket\Server(self::HOST, self::PORT);
        $this->ws->listen(self::HOST, self::FRONT_PORT, SWOOLE_SOCK_TCP); //http 接口
        $this->ws->set(self::CONFIG);
        $this->ws->on('start', [$this, 'onStart']);
        $this->ws->on('workerStart', [$this, 'onWorkerStart']);
        $this->ws->on('open', [$this, 'onOpen']);
        $this->ws->on('message', [$this, 'onMessage']);
        $this->ws->on('close', [$this, 'onClose']);

        $this->ws->on('task', [$this, 'onTask']);
        $this->ws->on('finish', [$this, 'onFinish']);


        $this->ws->start();
    }

    public function onStart($server)
    {
        swoole_set_process_name('hide-and-seek');
        echo sprintf("master start (listening on %s:%d)\n",
            self::HOST, self::PORT);
        DataCenter::initDataCenter(); //清除数据
    }

    public function onWorkerStart($server, $workerId)
    {
        DataCenter::$server = $server;

        echo "server: onWorkStart,worker_id:{$server->worker_id}\n";
    }
    public function onOpen($server, $request)
    {
        DataCenter::log(sprintf('client open fd：%d', $request->fd));

        $playerId = $request->get['player_id'];
        DataCenter::setPlayerInfo($playerId, $request->fd);
    }

    public function onMessage($server, $request)
    {
        DataCenter::log(sprintf('client open fd：%d，message：%s', $request->fd, $request->data));

        $data = json_decode($request->data, true);
        $playerId = DataCenter::getPlayerId($request->fd);
        switch ($data['code']) {
            case self::CLIENT_CODE_MATCH_PLAYER:
                $this->logic->matchPlayer($playerId);
                break;
            case self::CLIENT_CODE_START_ROOM:
                $this->logic->startRoom($data['room_id'], $playerId);
                break;
            case self::CLIENT_CODE_MOVE_PLAYER:
                $this->logic->movePlayer($data['direction'], $playerId);
                break;
        }
    }

    public function onClose($server, $fd)
    {
        DataCenter::log(sprintf('client close fd：%d', $fd));
        DataCenter::delPlayerInfo($fd);
    }

    public function onTask($server, $taskId, $srcWorkerId, $data)
    {
        DataCenter::log("onTask", $data);
        $result = [];
        switch ($data['code']) {
            case TaskManager::TASK_CODE_FIND_PLAYER:
                $ret = TaskManager::findPlayer();
                if (!empty($ret)) {
                    $result['data'] = $ret;
                }
                break;
        }
        if (!empty($result)) {
            $result['code'] = $data['code'];
            return $result;
        }
    }

    public function onFinish($server, $taskId, $data)
    {
        DataCenter::log("onFinish", $data);
        switch ($data['code']) {
            case TaskManager::TASK_CODE_FIND_PLAYER:
                $this->logic->createRoom($data['data']['red_player'],
                    $data['data']['blue_player']);
                break;
        }
    }
}
new Server();